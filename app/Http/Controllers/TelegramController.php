<?php

namespace App\Http\Controllers;

use App\Models\TelegramModel;
use Illuminate\Support\Facades\DB;

date_default_timezone_set('Asia/Makassar');

class TelegramController extends Controller
{
    public static function webhookOtomateBot()
    {
        $tokenBot = env('TELEGRAM_BOT_TOKEN');

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => "https://api.telegram.org/bot{$tokenBot}/getWebhookInfo",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST  => 'GET',
        ]);

        $response = curl_exec($curl);

        curl_close($curl);

        print_r($response);

        $result = json_decode($response);

        if ($result && isset($result->result->pending_update_count) && $result->result->pending_update_count != 0)
        {
            $url = 'https://otomate.telkomakses-borneo.id';

            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_URL            => "https://api.telegram.org/bot{$tokenBot}/setWebhook?url={$url}/api/telegram/otomateBot&max_connections=100&drop_pending_updates=true",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_CUSTOMREQUEST  => 'POST',
            ]);

            $response = curl_exec($curl);

            curl_close($curl);

            print_r(json_decode($response));
        }
        else
        {
            print_r("Pending Update Count is Zero \n");
        }
    }

    public static function otomateBot()
    {
        $tokenBot = env('TELEGRAM_BOT_TOKEN');

        $apiBot = 'https://api.telegram.org/bot' . $tokenBot;

        $update = @json_decode(file_get_contents('php://input'), true);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Start', 'callback_data' => '/start'],
                    ['text' => 'Clock', 'callback_data' => '/clock'],
                    ['text' => 'Chat ID', 'callback_data' => '/chat_id'],
                    ['text' => 'Search', 'callback_data' => '/search'],
                ],
            ],
        ];

        if (isset($update['callback_query']))
        {
            $callback  = $update['callback_query'];
            $chat_type = $callback['message']['chat']['type'] ?? '';
            $chat_id   = $callback['message']['chat']['id'];
            $messageID = $callback['message']['message_id'];
            $data      = $callback['data'];
            $chat_title = self::getChatTitle($callback['message']['chat'] ?? []);
            $thread_id = $callback['message']['message_thread_id'] ?? null;

            TelegramModel::answerCallbackQuery($tokenBot, $callback['id']);

            if ($chat_type !== 'private' && ($data === '/search'))
            {
                $message = 'Sorry, this command can only be used in private chat.';

                if ($thread_id)
                {
                    TelegramModel::sendMessageReplyThread($tokenBot, $chat_id, $thread_id, $message, $messageID);
                }
                else
                {
                    TelegramModel::sendMessageReplyMarkupKeyboard($tokenBot, $chat_id, $message, $keyboard);
                }

                return;
            }

            if ($data === '/start')
            {
                $hour = date('H', time());

                if ($hour > 6 && $hour <= 11)
                {
                    $saying = 'Good Morning';
                }
                elseif ($hour > 11 && $hour <= 15)
                {
                    $saying = 'Good Afternoon';
                }
                elseif ($hour > 15 && $hour <= 17)
                {
                    $saying = 'Good Evening';
                }
                elseif ($hour > 17 && $hour <= 23)
                {
                    $saying = 'Good Night';
                }
                else
                {
                    $saying = 'Good Night';
                }

                $message = "Hello $chat_title, $saying!";

                if ($thread_id)
                {
                    TelegramModel::sendMessageReplyThread($tokenBot, $chat_id, $thread_id, $message, $messageID);
                }
                else
                {
                    TelegramModel::sendMessageReplyMarkupKeyboard($tokenBot, $chat_id, $message, $keyboard);
                }

                return;
            }

            if ($data === '/clock')
            {
                $timeNow = date('Y-m-d H:i:s', time());
                $message = "Current Time : <b>$timeNow</b>";

                if ($thread_id)
                {
                    TelegramModel::sendMessageReplyThread($tokenBot, $chat_id, $thread_id, $message, $messageID);
                }
                else
                {
                    TelegramModel::sendMessageReplyMarkupKeyboard($tokenBot, $chat_id, $message, $keyboard);
                }

                return;
            }

            if ($data === '/chat_id')
            {
                $message  = "Name       : <b>$chat_title</b>\n";
                $message .= "Chat ID    : <b>$chat_id</b>\n";

                if (isset($callback['message']['message_thread_id']))
                {
                    $topic_id = $callback['message']['message_thread_id'];
                    $topic_name = $callback['message']['reply_to_message']['forum_topic_created']['name'] ?? '';

                    $message .= "\n";
                    $message .= "Topic Name : <b>$topic_name</b>\n";
                    $message .= "Topic ID   : <b>$topic_id</b>";
                }

                if ($thread_id)
                {
                    TelegramModel::sendMessageReplyThread($tokenBot, $chat_id, $thread_id, $message, $messageID);
                }
                else
                {
                    TelegramModel::sendMessageReplyMarkupKeyboard($tokenBot, $chat_id, $message, $keyboard);
                }

                return;
            }

            $message = 'Sorry, command not available.';

            if ($thread_id)
            {
                TelegramModel::sendMessageReplyThread($tokenBot, $chat_id, $thread_id, $message, $messageID);
            }
            else
            {
                TelegramModel::sendMessageReplyMarkupKeyboard($tokenBot, $chat_id, $message, $keyboard);
            }

            return;
        }

        if (isset($update['message']))
        {
            $chat_type = $update['message']['chat']['type'] ?? '';
            $chat_id   = $update['message']['chat']['id'];
            $messageID = $update['message']['message_id'];
            $text      = $update['message']['text'] ?? '';
            $photo     = $update['message']['photo'] ?? null;
            $location  = $update['message']['location'] ?? null;
            $thread_id = $update['message']['message_thread_id'] ?? null;

            if (! empty($text) && substr($text, 0, 1) == '/')
            {
                if ($chat_type !== 'private' && (strpos($text, '/search') === 0))
                {
                    $message = 'Sorry, this command can only be used in private chat.';

                    if ($thread_id)
                    {
                        TelegramModel::sendMessageReplyThread($tokenBot, $chat_id, $thread_id, $message, $messageID);
                    }
                    else
                    {
                        TelegramModel::sendMessageReplyMarkupKeyboard($tokenBot, $chat_id, $message, $keyboard);
                    }

                    return;
                }

                if (strpos($text, '/start') === 0)
                {
                    $hour       = date('H', time());
                    $chat_title = self::getChatTitle($update['message']['chat'] ?? []);

                    if ($hour > 6 && $hour <= 11)
                    {
                        $saying = 'Good Morning';
                    }
                    elseif ($hour > 11 && $hour <= 15)
                    {
                        $saying = 'Good Afternoon';
                    }
                    elseif ($hour > 15 && $hour <= 17)
                    {
                        $saying = 'Good Evening';
                    }
                    elseif ($hour > 17 && $hour <= 23)
                    {
                        $saying = 'Good Night';
                    }
                    else
                    {
                        $saying = 'Good Night';
                    }

                    $message = "Hello $chat_title, $saying!";

                    if ($thread_id)
                    {
                        TelegramModel::sendMessageReplyThread($tokenBot, $chat_id, $thread_id, $message, $messageID);
                    }
                    else
                    {
                        TelegramModel::sendMessageReplyMarkupKeyboard($tokenBot, $chat_id, $message, $keyboard);
                    }
                }
                elseif (strpos($text, '/clock') === 0)
                {
                    $timeNow = date('Y-m-d H:i:s', time());
                    $message = "Current Time : <b>$timeNow</b>";

                    if ($thread_id)
                    {
                        TelegramModel::sendMessageReplyThread($tokenBot, $chat_id, $thread_id, $message, $messageID);
                    }
                    else
                    {
                        TelegramModel::sendMessageReplyMarkupKeyboard($tokenBot, $chat_id, $message, $keyboard);
                    }
                }
                elseif (strpos($text, '/chat_id') === 0)
                {
                    $chat_title = self::getChatTitle($update['message']['chat'] ?? []);

                    $message  = "Name       : <b>$chat_title</b>\n";
                    $message .= "Chat ID    : <b>$chat_id</b>\n";

                    if (isset($update['message']['message_thread_id']))
                    {
                        $topic_id = $update['message']['message_thread_id'];
                        $topic_name = $update['message']['reply_to_message']['forum_topic_created']['name'] ?? '';

                        $message .= "\n";
                        $message .= "Topic Name : <b>$topic_name</b>\n";
                        $message .= "Topic ID   : <b>$topic_id</b>";
                    }

                    if ($thread_id)
                    {
                        TelegramModel::sendMessageReplyThread($tokenBot, $chat_id, $thread_id, $message, $messageID);
                    }
                    else
                    {
                        TelegramModel::sendMessageReplyMarkupKeyboard($tokenBot, $chat_id, $message, $keyboard);
                    }
                }
                else
                {
                    $message = 'Sorry, command not available.';

                    if ($thread_id)
                    {
                        TelegramModel::sendMessageReplyThread($tokenBot, $chat_id, $thread_id, $message, $messageID);
                    }
                    else
                    {
                        TelegramModel::sendMessageReplyMarkupKeyboard($tokenBot, $chat_id, $message, $keyboard);
                    }
                }
            }
        }
    }

    private static function getUserState($chat_id)
    {
    }

    private static function setUserState($chat_id, $data)
    {
    }

    private static function clearUserState($chat_id)
    {
    }

    private static function getChatTitle($chat)
    {
        if (isset($chat['title']))
        {
            return $chat['title'];
        }
        if (isset($chat['first_name']))
        {
            return $chat['first_name'] . (isset($chat['last_name']) ? ' ' . $chat['last_name'] : '');
        }

        return '';
    }
}
