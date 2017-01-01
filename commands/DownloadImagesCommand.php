<?php

namespace mpyw\TwitterAvatarCollector\Commands;

use Composer\Script\Event;
use Colors\Color;
use mpyw\Cowitter\Client;
use mpyw\Co\Co;

class DownloadImagesCommand extends AbstractCommand
{
    protected $config = [];
    protected $saved = [];
    protected $processing = [];

    /**
     * ダウンロードコマンドの実装
     *
     * @return \Generator
     */
    protected function downloadAsync()
    {
        $c = $this->color;

        $picture_directory = static::getHomeDir() . DIRECTORY_SEPARATOR . 'Pictures';
        if (!is_dir($picture_directory)) {
            $picture_directory = null;
        }

        $this->config = [
            'filtered_language' => $this->in('Filtered Language (e.g. ja, en, fr, ...)', [], ''),
            'max' => (int)$this->in('The number of downloaded files limit', ['ctype_digit'], ''),
            'use_screen_name' => $this->yesOrNo('Use screen_name instead of user_id for saved filenames?', false),
            'output_directory' => $this->in('Directory which downloaded images saved into', ['is_dir'], $picture_directory),
            'endpoint' => 'statuses/sample',
            'params' => [],
        ];

        // 認証
        $client = (yield $this->authorizeAsync());

        echo "Connecting to streaming...\n";

        while (true) {
            try {
                // ストリーミングに非同期で接続
                yield $client->streamingAsync(
                    $this->config['endpoint'],
                    function ($status) { yield Co::RETURN_WITH => $this->processAsync($status); },
                    $this->config['params']
                );
                // 終了
                break;
            } catch (\Exception $e) {
                // ストリーミング受信中に例外が発生した場合は警告を表示
                echo $c($e->getMessage())->danger . "\n";
                if (!$this->yesOrNo('Reconnect?')) {
                    // 再接続しない場合は終了
                    exit(1);
                }
            }
        }
    }

    /**
     * 流れてきたツイートを処理します
     *
     * @return \Generator
     */
    protected function processAsync($status)
    {
        $c = $this->color;

        switch (true) {
            // 必要件数ダウンロードが済んでいる場合はイベントループを終了
            case count($this->saved) >= $this->config['max'];
                yield Co::RETURN_WITH => false;
            // 現在ダウンロード中のものが終われば必要件数に到達する場合は単純に無視する
            // ダウンロード済みまたはダウンロード中のものとユーザが重複している場合は無視する
            // 言語フィルタリングでドロップされた場合は無視する
            case count($this->saved) + count($this->processing) >= $this->config['max']:
            case !isset($status->text):
            case isset($this->saved["u{$status->user->id_str}"]) || isset($this->processing["u{$status->user->id_str}"]):
            case $this->config['filtered_language'] !== '' && $status->user->lang !== $this->config['filtered_language']:
                return;
        }

        // ダウンロード中タスクに追加
        $this->processing["u{$status->user->id_str}"] = true;

        // 表示用のインデックス
        $index = count($this->saved) + count($this->processing) - 1;

        // プロフィール画像へのリクエストを生成
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $status->user->profile_image_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        try {

            // プロフィール画像のダウンロードを実行
            echo $c("[$index] Downloading avatar image of @{$status->user->screen_name} (user_id: {$status->user->id_str})\n")->info;
            $content = (yield $ch);
            if (count($this->saved) >= $this->config['max']) {
                // 終わったときに他の割り込みで最大件数に到達している場合は何もせずに終了
                unset($this->processing["u{$status->user->id_str}"]);
                yield Co::RETURN_WITH => false;
            }

            // 拡張子を求めて適切なファイル名で保存
            $info = getimagesizefromstring($content);
            if ($info === false) {
                throw new \RuntimException("[$index] Not a valid image");
            }
            $filename = implode([
                $this->config['output_directory'],
                DIRECTORY_SEPARATOR,
                $this->config['use_screen_name'] ? $status->user->screen_name : $status->user->id_str,
                str_replace('jpeg', 'jpg', image_type_to_extension($info[2])),
            ]);
            file_put_contents($filename, $content);

            // 後処理
            unset($this->processing["u{$status->user->id_str}"]);
            $this->saved["u{$status->user->id_str}"] = true;

            // これで最大件数に到達したら終了
            if (count($this->saved) >= $this->config['max']) {
                yield Co::RETURN_WITH => false;
            }

        } catch (\Exception $e) {

            // ダウンロード中に例外が発生した場合は警告を表示
            unset($this->processing["u{$status->user->id_str}"]);
            echo $c("[$index] {$e->getMessage()}\n")->warning;

        }
    }
}
