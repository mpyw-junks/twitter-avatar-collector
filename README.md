# Twitter Avatar Collector

ランダムなタイムラインからTwitterのアイコン画像を収集します  
Windowsユーザ向けにドキュメントを残しておきます

## Requirements

以下のコマンドをターミナルから使えるようにしてください  

- `git` [Git公式](https://git-scm.com/downloads)
- `php` [XAMPP公式](https://www.apachefriends.org/download.html) PHP 7.x 系を推奨
- `composer` [Composer公式](https://getcomposer.org/doc/00-intro.md#installation-windows) Composer-Setup.exe を利用

## Usage

PowerShellまたはコマンドプロンプトを作業場所で起動  
コマンドプロンプトは <kbd>Shift</kbd> + 右クリックで，現在のフォルダを起点に起動できます

1. `git clone https://github.com/mpyw-yattemita/twitter-avatar-collector`
2. `cd twitter-avatar-collector`
3. `composer install`
4. `composer download-images`

![Example](https://cloud.githubusercontent.com/assets/1351893/21580381/b6ed321e-d034-11e6-8abe-349bd0098e6e.png)

### `SSL certificate problem`

もしXAMPPを利用していて `SSL certificate problem` と表示される場合，以下の手順に従って最新の証明書をインストールしてください。

1. `cacert.pem` を [cURL公式](https://curl.haxx.se/docs/caextract.html) からダウンロード
2. `C:\xampp\cacert.pem` など，適当な場所に設置
3. `C:\xampp\php\php.ini` を以下のように書き換える

```
;curl.cainfo =
```
↓
```ini
curl.cainfo = "C:\xampp\cacert.pem"
```