<?php

namespace mpyw\TwitterAvatarCollector\Commands;

require_once __DIR__ . '/../vendor/autoload.php';

use Composer\Script\Event;
use Colors\Color;
use mpyw\Cowitter\Client;
use mpyw\Co\Co;

class DownloadImagesCommand
{
    protected $event;
    protected $argc;
    protected $argv;
    protected $color;

    /**
     * Composerから呼び出しに割り込みます
     *
     * @param  string $method
     * @param  array  $args
     * @return mixed
     */
    public static function __callStatic($method, array $args)
    {
        // コマンド消去
        echo "\033[1A\033[2K";
        // 初期化処理
        $instance = new static(...$args);
        // 本処理の実行
        return $instance->$method();
    }

    /**
     * コンストラクタ
     *
     * @param Event $event
     */
    protected function __construct(Event $event)
    {
        // 各種プロパティのセット (引数などは未使用)
        $this->event = $event;
        $this->argv = $event->getArguments();
        $this->argc = count($this->argv);
        $this->color = new Color;

        // テーマの設定
        $this->color->setTheme([
            'danger' => ['red'],
            'warning' => ['magenta'],
            'info' => ['cyan'],
            'success' => ['green'],
        ]);

        // ロゴの表示
        $c = $this->color;
        echo $c("\n****************************************\n")->green;
        echo $c("*                                      *\n")->green;
        echo $c("*    Twitter Avatar Collector Wizard   *\n")->green;
        echo $c("*                                      *\n")->green;
        echo $c("****************************************\n\n")->green;
        echo $c("Please input <warning>Ctrl + C</warning> when you want to abort.\n\n")->colorize();
    }

    /**
     * 1行入力を受け取ります
     *
     * @param  string $prompt     メッセージ
     * @param  array  $validators true/falseを返すバリデータの配列
     * @param  mixed  $default    NULLのときは無視される
     * @return string
     */
    protected function in($prompt, array $validators = [], $default = null)
    {
        $c = $this->color;
        while (true) {

            // デフォルト値の設定状況で表示を切り替える
            if ($default === null) {
                echo $c("$prompt [Required] $ ")->info;
            } elseif ($default === '') {
                echo $c("$prompt (Optional) $ ")->info;
            } else {
                echo $c("$prompt (Default: $default) $ ")->info;
            }

            // 1行受け取る (EOFなら失敗と見なして終了)
            $input = fgets(STDIN);
            if ($input === false) {
                echo $c("\nCommand aborted.\n")->danger;
                exit(1);
            }

            // トリミング
            $input = trim($input);

            // デフォルト値が設定されていない場合，空欄を許可しない
            if ($input === '' && $default === null) {
                echo $c("This field cannot be empty.\n")->warning;
                continue;
            }

            // デフォルト値が設定されている場合，空欄の代わりにそれを利用
            if ($input === '') {
                return $default;
            }

            // 入力値を検証
            foreach ($validators as $validator) {
                if (!$validator($input)) {
                    echo $c("Invalid input.\n")->warning;
                    continue 2;
                }
            }

            return $input;
        }
    }

    /**
     * Yes/No でブール値を受け取ります
     *
     * @param  string    $prompt  メッセージ
     * @param  bool|null $default NULLのときは無視される
     * @return bool
     */
    protected function yesOrNo($prompt, $default = null)
    {
        $answer = $this->in("$prompt (yes or no)", [function ($string) {
            return (bool)preg_match('/^(?:yes|no|y|n)$/i', $string);
        }], $default === null ? null : ($default ? 'yes' : 'no'));
        return (bool)preg_match('/^(?:yes|y)$/i', $answer);
    }

    /**
     * ホームディレクトリを取得
     *
     * @return string
     */
    protected static function getHomeDir()
    {
        return DIRECTORY_SEPARATOR === '\\' ? $_SERVER['USERPROFILE'] : $_SERVER['HOME'];
    }

    /**
     * ダウンロードコマンドの実装
     */
    protected function download()
    {
        $c = $this->color;

        // フィルタリングする言語 (空欄でフィルタリングしない)
        $filtered_language = $this->in('Filtered Language (e.g. ja, en, fr, ...)', [], '');

        // 最大件数
        $max = (int)$this->in('The number of downloaded files limit', ['ctype_digit'], '');

        // screen_name を user_id の代わりに使うかどうか？ (デフォルトは user_id)
        $use_screen_name = $this->yesOrNo('Use screen_name instead of user_id for saved filenames?', false);

        // 保存するディレクトリ (デフォルトはピクチャ)
        $picture_directory = static::getHomeDir() . DIRECTORY_SEPARATOR . 'Pictures';
        $output_directory = $this->in(
            'Directory which downloaded images saved into',
            ['is_dir'],
            is_dir($picture_directory) ? $picture_directory : null
        );

        while (true) {

            // クレデンシャルを受け取る
            $consumer_key = $this->in('Consumer Key (API Key)');
            $consumer_secret = $this->in('Consumer Secret (API Secret)');
            $access_token = $this->in('Access Token');
            $access_token_secret = $this->in('Access Token Secret');

            while (true) {
                try {
                    // 認証が成功したら何もしない
                    $client = new Client([$consumer_key, $consumer_secret, $access_token, $access_token_secret]);
                    echo "Verifying credential...\n";
                    $user = $client->get('account/verify_credentials');
                    echo $c("Logined as @{$user->screen_name} (user_id: {$user->id_str})\n")->success;
                    break 2;
                } catch (\Exception $e) {
                    // 認証が失敗したらリトライ
                    echo $c("{$e->getMessage()}\n")->danger;
                    // Yesで同じキーで再試行，Noでキー入力からやり直し (デフォルトはYes)
                    if (!$this->yesOrNo('Retry with the same credential?', true)) {
                        break;
                    }
                }
            }
        }

        echo "Connecting to POST statuses/sample...\n";

        $saved = [];
        $processing = [];

        while (true) {

            try {

                // ストリーミングに非同期で接続
                Co::wait($client->streamingAsync('statuses/sample',
                function ($status) use (&$saved, &$processing, $max, $use_screen_name, $filtered_language, $output_directory) {

                    $c = $this->color;

                    switch (true) {

                        // 必要件数ダウンロードが済んでいる場合はイベントループを終了
                        case count($saved) >= $max:
                            yield Co::RETURN_WITH => false;
                        // 現在ダウンロード中のものが終われば必要件数に到達する場合は単純に無視する
                        // ツイート以外のイベントは無視する
                        // ダウンロード済みまたはダウンロード中のものとユーザが重複している場合は無視する
                        // 言語フィルタリングでドロップされた場合は無視する
                        case count($saved) + count($processing) >= $max:
                        case !isset($status->text):
                        case isset($saved["u{$status->user->id_str}"]) || isset($processing["u{$status->user->id_str}"]):
                        case $filtered_language !== '' && $status->user->lang !== $filtered_language:
                            return;
                    }

                    // ダウンロード中タスクに追加
                    $processing["u{$status->user->id_str}"] = true;

                    // プロフィール画像へのリクエストを生成
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $status->user->profile_image_url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                    ]);

                    // 表示用のインデックスを計算
                    $index = count($saved) + count($processing) - 1;

                    try {

                        // プロフィール画像のダウンロードを実行
                        echo $c("[$index] Downloading avatar image of @{$status->user->screen_name} (user_id: {$status->user->id_str})\n")->info;
                        $content = (yield $ch);
                        if (count($saved) >= $max) {
                            // 終わったときに他の割り込みで最大件数に到達している場合は何もせずに終了
                            unset($processing["u{$status->user->id_str}"]);
                            yield Co::RETURN_WITH => false;
                        }

                        // 拡張子を求めて適切なファイル名で保存
                        $info = @getimagesizefromstring($content);
                        if ($info === false) {
                            throw new \RuntimException("[$index] Not a valid image");
                        }
                        $filename = implode([
                            $output_directory,
                            DIRECTORY_SEPARATOR,
                            $use_screen_name ? $status->user->screen_name : $status->user->id_str,
                            str_replace('jpeg', 'jpg', image_type_to_extension($info[2])),
                        ]);
                        file_put_contents($filename, $content);

                        // 後処理
                        unset($processing["u{$status->user->id_str}"]);
                        $saved["u{$status->user->id_str}"] = true;

                        // これで最大件数に到達したら終了
                        if (count($saved) >= $max) {
                            yield Co::RETURN_WITH => false;
                        }

                    } catch (\Exception $e) {

                        // ダウンロード中に例外が発生した場合は警告を表示
                        unset($processing["u{$status->user->id_str}"]);
                        echo $c("[$index] {$e->getMessage()}\n")->warning;

                    }

                }));

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
}
