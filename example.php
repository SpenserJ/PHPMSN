<?php
require_once 'msn/msn.php';
$msn = new MSN();
$msn->output = true;
$msn->functions['messageReceived']     = messageReceived;
$msn->signIn('username', 'password', 'display_name');

function messageReceived($messages, $convo) {
    if (strpos($messages[3], 'TypingUser') !== false) {
        // They are typing
        return;
    }
    // The real messages from the user start in section 5 of the array, 0-4 are headers
    for ($i=5; $i<count($messages); $i++) {
        $message = $messages[$i];
        switch(strtolower($message)) {
            case 'what time is it?':
                $convo->sendMessage('It is currently ' . date('g:i:sA', mktime()));
                break;
            case 'what is your name?':
                $convo->sendMessage('My name is dot the bot!');
                break;
            default:
                $convo->sendMessage('I don\'t know what to do with your message!');
                break;
        }
    }
}
?>