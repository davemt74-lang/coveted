<?php
declare(strict_types=1);

require_once __DIR__ . '/app/invite_crm.php';

$pdo = coveted_db();
$error = '';
$submitted = (string)($_GET['submitted'] ?? '') === '1';
$user = coveted_current_user();

try {
    coveted_invite_crm_ensure_schema($pdo);
    $cities = coveted_cities_list('active', $pdo);
} catch (Throwable $e) {
    error_log('Invite request city load failed: ' . $e->getMessage());
    $cities = [];
    $error = 'Invite requests are temporarily unavailable. Please try again shortly.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$user) {
    coveted_require_csrf();
    try {
        coveted_invite_request_submit($_POST, $pdo);
        coveted_redirect('/request-invite.php?submitted=1');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Public invite request failed: ' . $e->getMessage());
        $error = 'We could not save your request right now. Please try again.';
    }
}

$interestOptions = coveted_invite_event_interest_options();
$selectedInterests = coveted_invite_normalize_interests((array)($_POST['event_interests'] ?? []));
$postedCity = array_key_exists('city_id', $_POST) ? (string)$_POST['city_id'] : '';

coveted_page_start('Request an Invite');
?>
<div class="cv-invite-request-page">
    <section class="cv-invite-request-intro">
        <a class="cv-invite-request-back" href="/">← Coveted</a>
        <span class="cv-eyebrow">REQUEST AN INVITE</span>
        <h1>Find your way into the right room.</h1>
        <p>Tell us a little about yourself and the kinds of experiences you want to show up for. Coveted reviews requests personally as communities and events open in each city.</p>
    </section>

    <?php if ($user): ?>
        <section class="cv-card cv-invite-request-success">
            <span class="cv-eyebrow">YOU’RE ALREADY IN</span>
            <h2>Your Coveted account is active.</h2>
            <p>You do not need to request another invite. Open your member home to see your current groups, invitations and benefits.</p>
            <a class="cv-button cv-button-primary" href="/">Open Member Home</a>
        </section>
    <?php elseif ($submitted): ?>
        <section class="cv-card cv-invite-request-success">
            <span class="cv-eyebrow">REQUEST RECEIVED</span>
            <h2>We have your information.</h2>
            <p>We’ll review your request against upcoming Coveted communities and experiences in your city. If there is a fit, the next step will come directly from Coveted.</p>
            <a class="cv-button cv-button-primary" href="/">Back to Coveted</a>
        </section>
    <?php else: ?>
        <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

        <form class="cv-card cv-invite-request-form" method="post" action="/request-invite.php" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <label class="cv-invite-honeypot" aria-hidden="true">Company<input name="company" tabindex="-1" autocomplete="off"></label>

            <div class="cv-invite-form-section">
                <div><span>01</span><h2>About you</h2></div>
                <div class="cv-invite-form-grid">
                    <label>
                        Name
                        <input name="name" maxlength="180" required autocomplete="name" value="<?= coveted_e((string)($_POST['name'] ?? '')) ?>">
                    </label>
                    <label>
                        Email
                        <input type="email" name="email" maxlength="255" required autocomplete="email" value="<?= coveted_e((string)($_POST['email'] ?? '')) ?>">
                    </label>
                    <label>
                        Phone <small>Optional</small>
                        <input name="phone" maxlength="80" autocomplete="tel" value="<?= coveted_e((string)($_POST['phone'] ?? '')) ?>">
                    </label>
                    <label>
                        City
                        <select name="city_id" required data-city-select>
                            <option value="" disabled <?= $postedCity === '' ? 'selected' : '' ?>>Choose your city</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?= (int)$city['id'] ?>" <?= $postedCity !== '' && (int)$postedCity === (int)$city['id'] ? 'selected' : '' ?>><?= coveted_e(coveted_city_label($city)) ?></option>
                            <?php endforeach; ?>
                            <option value="0" <?= $postedCity === '0' ? 'selected' : '' ?>>Other / not listed</option>
                        </select>
                    </label>
                    <label class="cv-invite-grid-wide" data-city-other <?= $postedCity === '0' ? '' : 'hidden' ?>>
                        Your city
                        <input name="city_other" maxlength="180" placeholder="City, State / Region" value="<?= coveted_e((string)($_POST['city_other'] ?? '')) ?>" <?= $postedCity === '0' ? 'required' : '' ?> data-city-other-input>
                    </label>
                </div>
            </div>

            <div class="cv-invite-form-section">
                <div><span>02</span><h2>Interested in events</h2><p>Select all that fit.</p></div>
                <fieldset class="cv-invite-interest-grid">
                    <legend class="cv-sr-only">Event interests</legend>
                    <?php foreach ($interestOptions as $key => $label): ?>
                        <label>
                            <input type="checkbox" name="event_interests[]" value="<?= coveted_e($key) ?>" <?= in_array($key, $selectedInterests, true) ? 'checked' : '' ?>>
                            <span><?= coveted_e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            </div>

            <div class="cv-invite-form-section">
                <div><span>03</span><h2>Anything else?</h2></div>
                <div class="cv-invite-form-grid">
                    <label class="cv-invite-grid-wide">
                        How did you hear about Coveted? <small>Optional</small>
                        <input name="how_heard" maxlength="180" value="<?= coveted_e((string)($_POST['how_heard'] ?? '')) ?>">
                    </label>
                    <label class="cv-invite-grid-wide">
                        What are you hoping to find? <small>Optional</small>
                        <textarea name="message" maxlength="2000" rows="5"><?= coveted_e((string)($_POST['message'] ?? '')) ?></textarea>
                    </label>
                </div>
            </div>

            <div class="cv-invite-request-submit">
                <p>Submitting a request does not guarantee membership. We use this information only to review your Coveted invite request and match you with relevant city experiences.</p>
                <button class="cv-button cv-button-primary" type="submit">Request an Invite <span aria-hidden="true">→</span></button>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php coveted_page_end(); ?>
