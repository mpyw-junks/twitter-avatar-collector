<?php

namespace mpyw\TwitterAvatarCollector\Commands;

require_once __DIR__ . '/../vendor/autoload.php';

use Composer\Script\Event;
use Colors\Color;
use mpyw\Cowitter\Client;
use mpyw\Co\Co;

class AbstractCommand
{
    protected $theme = [
        'danger' => ['red'],
        'warning' => ['magenta'],
        'info' => ['cyan'],
        'success' => ['green'],
    ];
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
        return Co::wait(function () use ($method, $args) {
            $instance = new static(...$args);
            yield CO::RETURN_WITH => $instance->$method();
        });
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
        $this->color->setTheme($this->theme);

        $this->initializeConsole();
    }

    /**
     * コンソールを初期化します
     */
    protected function initializeConsole()
    {
        // コマンド消去
        echo "\033[1A\033[2K";

        // ロゴの表示
        $c = $this->color;
        echo $c("\n****************************************\n")->success;
        echo $c("*                                      *\n")->success;
        echo $c("*    Twitter Avatar Collector Wizard   *\n")->success;
        echo $c("*                                      *\n")->success;
        echo $c("****************************************\n\n")->success;
        echo $c("Please input {$c('Ctrl + C')->warning} when you want to abort.\n\n");
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
     * OAuth認証が通るかどうか確認してClientのインスタンスを返します
     *
     * @return \Generator
     */
    protected function authorizeAsync()
    {
        $c = $this->color;
        while (true) {
            // クレデンシャルを受け取る
            $client = new Client([
                $this->in('Consumer Key (API Key)'),
                $this->in('Consumer Secret (API Secret)'),
                $this->in('Access Token'),
                $this->in('Access Token Secret'),
            ]);
            while (true) {
                try {
                    // 認証が成功したら何もしない
                    echo "Verifying credential...\n";
                    $user = (yield $client->getAsync('account/verify_credentials'));
                    echo $c("Logined as @{$user->screen_name} (user_id: {$user->id_str})\n")->success;
                    yield Co::RETURN_WITH => $client;
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
}
