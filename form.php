<?php
declare(strict_types=1);

/**
 * MTI連携 個人情報入力フォーム
 * PHP 8.x想定 / DBなし / フレームワークなし
 *
 * 画面：入力画面 → 確認画面 → 送信完了画面
 *
 * 仕様：
 * - MTIからPOSTされたIDをセッションに保持
 * - 登録者、管理者、管理用Gmailへメール送信
 * - DB登録なし
 */

session_start();
mb_language('Japanese');
mb_internal_encoding('UTF-8');

// ==============================
// 設定
// ==============================

const ADMIN_EMAIL = 'hi.kuhara.98@gmail.com';
const MANAGEMENT_GMAIL = 'hi.kuhara.98@gmail.com';

// 実運用では、取得したドメインのメールアドレスに変更推奨
const FROM_EMAIL = 'no-reply@example.com';
const SITE_NAME = 'お客様情報受付フォーム';

// ==============================
// 共通関数
// ==============================

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function selected(string $value, string $current): string
{
    return $value === $current ? ' selected' : '';
}

function getPrefectures(): array
{
    return [
        '北海道','青森県','岩手県','宮城県','秋田県','山形県','福島県',
        '茨城県','栃木県','群馬県','埼玉県','千葉県','東京都','神奈川県',
        '新潟県','富山県','石川県','福井県','山梨県','長野県',
        '岐阜県','静岡県','愛知県','三重県',
        '滋賀県','京都府','大阪府','兵庫県','奈良県','和歌山県',
        '鳥取県','島根県','岡山県','広島県','山口県',
        '徳島県','香川県','愛媛県','高知県',
        '福岡県','佐賀県','長崎県','熊本県','大分県','宮崎県','鹿児島県','沖縄県'
    ];
}

function getEmptyData(): array
{
    return [
        'name' => '',
        'kana' => '',
        'birth_year' => '',
        'birth_month' => '',
        'birth_day' => '',
        'email' => '',
        'zip' => '',
        'prefecture' => '',
        'address' => '',
        'building' => '',
    ];
}

function getInputData(): array
{
    return [
        'name' => post('name'),
        'kana' => post('kana'),
        'birth_year' => post('birth_year'),
        'birth_month' => post('birth_month'),
        'birth_day' => post('birth_day'),
        'email' => post('email'),
        'zip' => post('zip'),
        'prefecture' => post('prefecture'),
        'address' => post('address'),
        'building' => post('building'),
    ];
}

function validate(array $data): array
{
    $errors = [];

    if ($data['name'] === '') $errors[] = '氏名を入力してください。';
    if ($data['kana'] === '') $errors[] = 'フリガナを入力してください。';

    if ($data['birth_year'] === '' || $data['birth_month'] === '' || $data['birth_day'] === '') {
        $errors[] = '生年月日を選択してください。';
    } else {
        $year = (int)$data['birth_year'];
        $month = (int)$data['birth_month'];
        $day = (int)$data['birth_day'];
        if (!checkdate($month, $day, $year)) {
            $errors[] = '正しい生年月日を選択してください。';
        }
    }

    if ($data['email'] === '') {
        $errors[] = 'メールアドレスを入力してください。';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'メールアドレスの形式が正しくありません。';
    }

    if ($data['zip'] === '') $errors[] = '郵便番号を入力してください。';
    if ($data['prefecture'] === '') $errors[] = '都道府県を選択してください。';
    if ($data['address'] === '') $errors[] = '住所を入力してください。';

    return $errors;
}

function createMailBody(array $data): string
{
    $mtiId = $_SESSION['mti_id'] ?? '';
    $submittedAt = date('Y-m-d H:i:s');

    return <<<EOT
以下の内容でお客様情報を受け付けました。

【MTI連携ID】
{$mtiId}

【氏名】
{$data['name']}

【フリガナ】
{$data['kana']}

【生年月日】
{$data['birth_year']}年{$data['birth_month']}月{$data['birth_day']}日

【メールアドレス】
{$data['email']}

【郵便番号】
{$data['zip']}

【都道府県】
{$data['prefecture']}

【住所】
{$data['address']}

【建物名・部屋番号】
{$data['building']}

【送信日時】
{$submittedAt}

EOT;
}

function createGmailBodyForGas(array $data): string
{
    $mtiId = $_SESSION['mti_id'] ?? '';
    $submittedAt = date('Y-m-d H:i:s');

    // GASで解析しやすい key=value 形式
    return <<<EOT
GAS_IMPORT_START
mti_id={$mtiId}
name={$data['name']}
kana={$data['kana']}
birth_date={$data['birth_year']}-{$data['birth_month']}-{$data['birth_day']}
email={$data['email']}
zip={$data['zip']}
prefecture={$data['prefecture']}
address={$data['address']}
building={$data['building']}
submitted_at={$submittedAt}
GAS_IMPORT_END
EOT;
}

function sendMail(string $to, string $subject, string $body): bool
{
    $headers = [];
    $headers[] = 'From: ' . SITE_NAME . ' <' . FROM_EMAIL . '>';
    $headers[] = 'Reply-To: ' . FROM_EMAIL;
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    return mb_send_mail($to, $subject, $body, implode("\r\n", $headers));
}

// ==============================
// MTIからPOSTされたIDをセッション保持
// ==============================

// MTI側のPOST項目名が未確定のため、複数候補を受けられるようにしています。
// 確定後は1つに固定してください。
$mtiIdCandidates = ['mti_id', 'id', 'user_id', 'customer_id'];
foreach ($mtiIdCandidates as $key) {
    if (!empty($_POST[$key])) {
        $_SESSION['mti_id'] = trim((string)$_POST[$key]);
        break;
    }
}

// テスト表示用。MTIからIDが来ていない場合でも画面確認できるようにしています。
if (empty($_SESSION['mti_id'])) {
    $_SESSION['mti_id'] = 'TEST-MTI-ID';
}

// ==============================
// 画面制御
// ==============================

$mode = post('mode', 'input');
$errors = [];
$data = $_SESSION['form_data'] ?? getEmptyData();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'confirm') {
        $data = getInputData();
        $errors = validate($data);

        if (empty($errors)) {
            $_SESSION['form_data'] = $data;
        } else {
            $mode = 'input';
        }
    }

    if ($mode === 'complete') {
        $data = $_SESSION['form_data'] ?? getInputData();
        $errors = validate($data);

        if (empty($errors)) {
            $normalBody = createMailBody($data);
            $gasBody = createGmailBodyForGas($data);

            // 1. 登録者にメール送信
            sendMail($data['email'], '【' . SITE_NAME . '】受付完了のお知らせ', $normalBody);

            // 2. 管理者にメール送信
            sendMail(ADMIN_EMAIL, '【管理者通知】お客様情報が送信されました', $normalBody);

            // 3. 管理用Gmailにメール送信
            // GASがこのメールを読み取り、スプレッドシートへ1送信1行で追記する想定
            sendMail(MANAGEMENT_GMAIL, '【GAS取込用】お客様情報送信', $gasBody);

            unset($_SESSION['form_data']);
        } else {
            $mode = 'input';
        }
    }
}

$prefectures = getPrefectures();
$currentYear = (int)date('Y');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h(SITE_NAME) ?></title>
  <style>
    :root {
      --bg: #f5f5f7;
      --card: #ffffff;
      --text: #1d1d1f;
      --muted: #6e6e73;
      --field: #fbfbfd;
      --accent: #0071e3;
      --accent-dark: #005bb5;
      --success: #34c759;
      --error: #ff3b30;
      --shadow: 0 18px 60px rgba(0,0,0,0.10);
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", "Hiragino Sans", "Yu Gothic", sans-serif;
      background: var(--bg);
      color: var(--text);
    }
    main {
      width: 100%;
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: flex-start;
      padding: 18px 14px 34px;
    }
    .screen {
      width: 100%;
      max-width: 440px;
      background: var(--card);
      border-radius: 28px;
      box-shadow: var(--shadow);
      overflow: hidden;
    }
    .header {
      padding: 30px 22px 20px;
      border-bottom: 1px solid #ececef;
      background: linear-gradient(180deg, #ffffff, #f8f8fa);
    }
    .header h1 {
      margin: 0;
      font-size: 28px;
      line-height: 1.2;
      letter-spacing: -0.045em;
      font-weight: 760;
    }
    .header p {
      margin: 10px 0 0;
      color: var(--muted);
      font-size: 14px;
      line-height: 1.65;
    }
    .content { padding: 22px; }
    .form { display: grid; gap: 15px; }
    label {
      display: block;
      color: var(--muted);
      font-size: 13px;
      font-weight: 700;
    }
    input, select {
      width: 100%;
      appearance: none;
      margin-top: 8px;
      padding: 15px 14px;
      border: 1px solid #d7d7dc;
      border-radius: 15px;
      background: var(--field);
      color: var(--text);
      font-size: 16px;
      outline: none;
      transition: .18s ease;
    }
    input:focus, select:focus {
      border-color: var(--accent);
      background: #ffffff;
      box-shadow: 0 0 0 4px rgba(0,113,227,0.13);
    }
    select {
      background-image: linear-gradient(45deg, transparent 50%, #777 50%), linear-gradient(135deg, #777 50%, transparent 50%);
      background-position: calc(100% - 20px) calc(50% + 1px), calc(100% - 15px) calc(50% + 1px);
      background-size: 5px 5px, 5px 5px;
      background-repeat: no-repeat;
    }
    .row { display: grid; grid-template-columns: 1fr; gap: 15px; }
    .birth-row { display: grid; grid-template-columns: 1.25fr 1fr 1fr; gap: 10px; }
    .hint { margin-top: 8px; color: var(--muted); font-size: 12px; line-height: 1.55; }
    .buttons { display: grid; gap: 10px; margin-top: 24px; }
    .buttons.two { grid-template-columns: 1fr 1fr; }
    button {
      width: 100%;
      border: 0;
      border-radius: 999px;
      padding: 16px 18px;
      font-size: 16px;
      font-weight: 760;
      cursor: pointer;
      transition: .2s ease;
    }
    .primary { background: var(--accent); color: #ffffff; }
    .primary:hover { background: var(--accent-dark); }
    .secondary { background: #f0f0f2; color: var(--text); }
    .summary {
      overflow: hidden;
      border-radius: 22px;
      border: 1px solid #e8e8ed;
      background: #fbfbfd;
    }
    .summary-row {
      display: grid;
      grid-template-columns: 105px 1fr;
      gap: 12px;
      padding: 15px 16px;
      border-bottom: 1px solid #ededf0;
      font-size: 14px;
    }
    .summary-row:last-child { border-bottom: 0; }
    .key { color: var(--muted); font-weight: 760; }
    .value { color: var(--text); line-height: 1.55; word-break: break-word; }
    .error-box {
      margin-bottom: 18px;
      padding: 14px 15px;
      border-radius: 16px;
      background: #fff2f2;
      color: var(--error);
      font-size: 13px;
      line-height: 1.65;
    }
    .error-box ul { margin: 0; padding-left: 18px; }
    .complete { padding: 46px 22px 30px; text-align: center; }
    .check {
      width: 82px;
      height: 82px;
      margin: 0 auto 22px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      background: var(--success);
      color: #ffffff;
      font-size: 42px;
      font-weight: 800;
      box-shadow: 0 18px 42px rgba(52,199,89,0.28);
    }
    .complete h1 { margin: 0; font-size: 28px; letter-spacing: -0.045em; line-height: 1.25; }
    .complete p { margin: 14px auto 0; max-width: 330px; color: var(--muted); line-height: 1.75; font-size: 14px; }
    @media (min-width: 720px) {
      main { align-items: center; padding: 40px 20px; }
      .screen { max-width: 520px; border-radius: 32px; }
      .header { padding: 36px 34px 24px; }
      .header h1 { font-size: 34px; }
      .content { padding: 28px 34px 34px; }
      .row { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 360px) {
      main { padding: 10px; }
      .screen { border-radius: 24px; }
      .header, .content { padding-left: 18px; padding-right: 18px; }
      .summary-row { grid-template-columns: 1fr; gap: 5px; }
      .buttons.two { grid-template-columns: 1fr; }
      .birth-row { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<main>
  <?php if ($mode === 'input'): ?>
    <section class="screen">
      <div class="header">
        <h1>お客様情報の入力</h1>
        <p>必要事項をご入力のうえ、確認画面へお進みください。</p>
      </div>
      <div class="content">
        <?php if (!empty($errors)): ?>
          <div class="error-box"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form class="form" method="post" action="">
          <input type="hidden" name="mode" value="confirm">
          <div class="row">
            <label>氏名<input type="text" name="name" value="<?= h($data['name']) ?>" required></label>
            <label>フリガナ<input type="text" name="kana" value="<?= h($data['kana']) ?>" required></label>
          </div>
          <label>生年月日
            <div class="birth-row">
              <select name="birth_year" required>
                <option value="">年</option>
                <?php for ($year = $currentYear; $year >= 1900; $year--): ?>
                  <option value="<?= $year ?>"<?= selected((string)$year, (string)$data['birth_year']) ?>><?= $year ?>年</option>
                <?php endfor; ?>
              </select>
              <select name="birth_month" required>
                <option value="">月</option>
                <?php for ($month = 1; $month <= 12; $month++): ?>
                  <?php $m = sprintf('%02d', $month); ?>
                  <option value="<?= $m ?>"<?= selected($m, (string)$data['birth_month']) ?>><?= $month ?>月</option>
                <?php endfor; ?>
              </select>
              <select name="birth_day" required>
                <option value="">日</option>
                <?php for ($day = 1; $day <= 31; $day++): ?>
                  <?php $d = sprintf('%02d', $day); ?>
                  <option value="<?= $d ?>"<?= selected($d, (string)$data['birth_day']) ?>><?= $day ?>日</option>
                <?php endfor; ?>
              </select>
            </div>
          </label>
          <label>メールアドレス<input type="email" name="email" value="<?= h($data['email']) ?>" required><div class="hint">送信完了後、確認メールをお送りします。</div></label>
          <div class="row">
            <label>郵便番号<input type="text" name="zip" id="zip" value="<?= h($data['zip']) ?>" required><div class="hint">郵便番号入力後、住所自動入力が可能です。</div></label>
            <label>都道府県
              <select name="prefecture" id="prefecture" required>
                <option value="">選択してください</option>
                <?php foreach ($prefectures as $prefecture): ?>
                  <option value="<?= h($prefecture) ?>"<?= selected($prefecture, (string)$data['prefecture']) ?>><?= h($prefecture) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <label>住所<input type="text" name="address" id="address" value="<?= h($data['address']) ?>" required></label>
          <label>建物名・部屋番号<input type="text" name="building" value="<?= h($data['building']) ?>"></label>
          <div class="buttons"><button type="submit" class="primary">確認画面へ</button></div>
        </form>
      </div>
    </section>
  <?php elseif ($mode === 'confirm'): ?>
    <section class="screen">
      <div class="header"><h1>入力内容の確認</h1><p>内容にお間違いがないかご確認ください。</p></div>
      <div class="content">
        <div class="summary">
          <div class="summary-row"><div class="key">氏名</div><div class="value"><?= h($data['name']) ?></div></div>
          <div class="summary-row"><div class="key">フリガナ</div><div class="value"><?= h($data['kana']) ?></div></div>
          <div class="summary-row"><div class="key">生年月日</div><div class="value"><?= h($data['birth_year']) ?>年<?= h((string)(int)$data['birth_month']) ?>月<?= h((string)(int)$data['birth_day']) ?>日</div></div>
          <div class="summary-row"><div class="key">メール</div><div class="value"><?= h($data['email']) ?></div></div>
          <div class="summary-row"><div class="key">郵便番号</div><div class="value"><?= h($data['zip']) ?></div></div>
          <div class="summary-row"><div class="key">都道府県</div><div class="value"><?= h($data['prefecture']) ?></div></div>
          <div class="summary-row"><div class="key">住所</div><div class="value"><?= h($data['address']) ?></div></div>
          <div class="summary-row"><div class="key">建物名</div><div class="value"><?= h($data['building']) ?></div></div>
        </div>
        <div class="buttons two">
          <form method="post" action="">
            <input type="hidden" name="mode" value="input">
            <?php foreach ($data as $key => $value): ?><input type="hidden" name="<?= h($key) ?>" value="<?= h($value) ?>"><?php endforeach; ?>
            <button type="submit" class="secondary">修正する</button>
          </form>
          <form method="post" action="">
            <input type="hidden" name="mode" value="complete">
            <button type="submit" class="primary">送信する</button>
          </form>
        </div>
      </div>
    </section>
  <?php elseif ($mode === 'complete'): ?>
    <section class="screen">
      <div class="complete">
        <div class="check">✓</div>
        <h1>送信が完了しました</h1>
        <p>ご入力いただいた情報を受け付けました。確認メールを送信しましたので、ご確認ください。</p>
        <div class="buttons">
          <form method="post" action=""><input type="hidden" name="mode" value="input"><button type="submit" class="primary">入力画面へ戻る</button></form>
        </div>
      </div>
    </section>
  <?php endif; ?>
</main>
<script>
  const zipInput = document.getElementById('zip');
  if (zipInput) {
    zipInput.addEventListener('blur', async () => {
      const zip = zipInput.value.replace(/[^0-9]/g, '');
      if (zip.length !== 7) return;
      try {
        const response = await fetch(`https://zipcloud.ibsnet.co.jp/api/search?zipcode=${zip}`);
        const json = await response.json();
        if (json && json.results && json.results[0]) {
          const result = json.results[0];
          const prefecture = document.getElementById('prefecture');
          const address = document.getElementById('address');
          if (prefecture) prefecture.value = result.address1;
          if (address) address.value = `${result.address2}${result.address3}`;
        }
      } catch (error) {
        console.warn('住所自動入力に失敗しました。', error);
      }
    });
  }
</script>
</body>
</html>
