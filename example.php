<?php
require_once 'msn/msn.php';
$msn = new MSN();
$msn->functions['messageReceived']     = messageReceived;
$msn->signIn('username', 'password', 'display_name');

function messageReceived($message, $convo) {
    if (strpos($message[3], 'TypingUser') !== false) {
        // They are typing
        return;
    }
    switch(strtolower($message)) {
        case 'what time is it?':
            $convo->sendMessage('It is currently ' . date('g:i:sA', mktime());
            break;
        case 'what is your name?':
            $convo->sendMessage('My name is dot the bot!');
            break
        default:
            $convo->sendMessage('I don\' know what to do with your message!');
            break;
    }
}
?>