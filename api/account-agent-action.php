<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/account_agent_threads.php';
require_once dirname(__DIR__) . '/app/events.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $user=coveted_require_user();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST') {
        http_response_code(405);
        echo coveted_json(['ok'=>false,'error'=>'POST required.']);
        exit;
    }
    coveted_require_csrf();
    if (coveted_is_system_admin($user)) {
        throw new InvalidArgumentException('Use the dedicated Admin Agent for System Admin actions.');
    }
    if ((string)($_POST['confirmed'] ?? '')!=='1') {
        throw new InvalidArgumentException('Explicit member confirmation is required.');
    }

    $action=strtolower(trim((string)($_POST['action'] ?? '')));
    if (!in_array($action,['respond_invitation','set_rsvp'],true)) {
        throw new InvalidArgumentException('Unsupported Concierge action.');
    }
    $targetRef=trim((string)($_POST['target_ref'] ?? ''));
    if ($targetRef==='' || strlen($targetRef)>64) {
        throw new InvalidArgumentException('Action target is unavailable.');
    }
    $requestId=coveted_account_agent_request_id((string)($_POST['request_id'] ?? ''));

    $now=time();
    $recent=array_values(array_filter(
        (array)($_SESSION['member_concierge_action_timestamps'] ?? []),
        static fn(mixed $ts):bool=>is_int($ts) && $ts >= $now-300
    ));
    if (count($recent)>=12) {
        http_response_code(429);
        echo coveted_json(['ok'=>false,'error'=>'Too many Concierge actions. Try again after a short break.']);
        exit;
    }

    $results=(array)($_SESSION['member_concierge_action_results'] ?? []);
    if (isset($results[$requestId]) && is_array($results[$requestId])) {
        echo coveted_json($results[$requestId]+['replayed'=>true]);
        exit;
    }

    $decision=strtolower(trim((string)($_POST['decision'] ?? '')));
    $guestCount=(int)($_POST['guest_count'] ?? 0);
    if ($guestCount<0 || $guestCount>1) {
        throw new InvalidArgumentException('Guest count is invalid.');
    }

    if ($action==='respond_invitation') {
        if (!in_array($decision,['accepted','declined'],true)) {
            throw new InvalidArgumentException('Choose Accept or Maybe later.');
        }
        $response=coveted_event_respond_invitation($user,$targetRef,$decision,$decision==='accepted'?$guestCount:0);
        $message=match($response){
            'attending'=>$guestCount===1?'Confirmed. You and your guest are attending.':'Confirmed. You are attending.',
            'waitlist'=>'Confirmed. The Event is currently full, so you are on the waitlist.',
            default=>'Confirmed. You declined that invitation.',
        };
    } else {
        if (!in_array($decision,['attending','declined'],true)) {
            throw new InvalidArgumentException('Choose an RSVP response.');
        }
        $response=coveted_event_set_rsvp($user,$targetRef,$decision,$decision==='attending'?$guestCount:0);
        $message=match($response){
            'attending'=>$guestCount===1?'Confirmed. You and your guest are attending.':'Confirmed. Your RSVP is attending.',
            'waitlist'=>'Confirmed. The Event is currently full, so you are on the waitlist.',
            default=>'Confirmed. Your RSVP is now declined.',
        };
    }

    $threadRef=trim((string)($_POST['thread_ref'] ?? ''));
    if ($threadRef!=='') {
        try {
            $pdo=coveted_db();
            $thread=coveted_account_agent_thread_by_ref($user,$threadRef,$pdo);
            if ($thread && $thread['status']==='active') {
                coveted_account_agent_append_message(
                    $user,
                    $threadRef,
                    'assistant',
                    $message,
                    $requestId,
                    null,
                    null,
                    ['source'=>'member_concierge_action','action'=>$action,'target_ref'=>$targetRef,'result'=>$response],
                    $pdo
                );
            }
        } catch (Throwable $persistError) {
            error_log('Member Concierge action result could not be persisted: '.$persistError->getMessage());
        }
    }

    coveted_audit(
        'account.member_concierge_action_confirmed',
        'account',
        (string)$user['public_id'],
        ['action'=>$action,'target_ref'=>$targetRef,'decision'=>$decision,'result'=>$response],
        (int)$user['id']
    );

    $recent[]=$now;
    $_SESSION['member_concierge_action_timestamps']=$recent;
    $payload=[
        'ok'=>true,
        'message'=>$message,
        'result'=>$response,
        'action'=>$action,
        'replayed'=>false,
        'refresh'=>true,
    ];
    $results[$requestId]=$payload;
    if (count($results)>30) $results=array_slice($results,-30,null,true);
    $_SESSION['member_concierge_action_results']=$results;
    echo coveted_json($payload);
} catch (Throwable $e) {
    error_log('Member Concierge action failed: '.$e->getMessage());
    http_response_code($e instanceof InvalidArgumentException?400:503);
    echo coveted_json([
        'ok'=>false,
        'error'=>$e instanceof InvalidArgumentException?$e->getMessage():'The Concierge could not complete that action.',
    ]);
}
