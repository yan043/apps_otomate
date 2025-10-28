<?php

namespace App\Http\Controllers;

use App\Models\TelegramModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

date_default_timezone_set('Asia/Makassar');

class TelegramController extends Controller
{
    public static function webhookOtomateBot()
    {
        try
        {
            $tokenBot = env('TELEGRAM_BOT_TOKEN');

            if (!$tokenBot)
            {
                Log::error('Telegram bot token not found');
                return response()->json(['error' => 'Bot token not configured'], 500);
            }

            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_URL            => "https://api.telegram.org/bot{$tokenBot}/getWebhookInfo",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_CUSTOMREQUEST  => 'GET',
                CURLOPT_TIMEOUT        => 30,
            ]);

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);

            if ($curlError)
            {
                Log::error('Curl error in webhookOtomateBot: ' . $curlError);
                return response()->json(['error' => 'Failed to connect to Telegram API'], 500);
            }

            if ($httpCode !== 200)
            {
                Log::error('HTTP error in webhookOtomateBot: ' . $httpCode);
                return response()->json(['error' => 'Telegram API returned error'], 500);
            }

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
                    CURLOPT_TIMEOUT        => 30,
                ]);

                $response = curl_exec($curl);
                $curlError = curl_error($curl);
                curl_close($curl);

                if ($curlError)
                {
                    Log::error('Curl error in setWebhook: ' . $curlError);
                    return response()->json(['error' => 'Failed to set webhook'], 500);
                }

                print_r(json_decode($response));
            }
            else
            {
                print_r("Pending Update Count is Zero \n");
            }

            return response()->json(['success' => true]);
        }
        catch (Exception $e)
        {
            Log::error('Exception in webhookOtomateBot: ' . $e->getMessage());
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    public static function otomateBot()
    {
        try
        {
            $tokenBot = env('TELEGRAM_BOT_TOKEN');

            if (!$tokenBot)
            {
                Log::error('Telegram bot token not found');
                return response()->json(['error' => 'Bot token not configured'], 500);
            }

            $input = file_get_contents('php://input');
            if (!$input)
            {
                Log::warning('Empty input received');
                return response()->json(['error' => 'No data received'], 400);
            }

            $update = json_decode($input, true);
            if (!$update)
            {
                Log::error('Invalid JSON received: ' . $input);
                return response()->json(['error' => 'Invalid JSON'], 400);
            }

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => 'Start', 'callback_data' => '/start'],
                        ['text' => 'Clock', 'callback_data' => '/clock']
                    ],
                    [
                        ['text' => 'Chat ID', 'callback_data' => '/chat_id'],
                        ['text' => 'Search Site', 'callback_data' => '/search_site']
                    ],
                ],
            ];

            if (isset($update['callback_query']))
            {
                return self::handleCallbackQuery($update['callback_query'], $tokenBot, $keyboard);
            }

            if (isset($update['message']))
            {
                return self::handleMessage($update['message'], $tokenBot, $keyboard);
            }

            Log::warning('Unknown update type received: ' . json_encode($update));
            return response()->json(['error' => 'Unknown update type'], 400);
        }
        catch (Exception $e)
        {
            Log::error('Exception in otomateBot: ' . $e->getMessage());
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    private static function handleCallbackQuery($callback, $tokenBot, $keyboard)
    {
        try
        {
            $chat_type = $callback['message']['chat']['type'] ?? '';
            $chat_id = $callback['message']['chat']['id'];
            $messageID = $callback['message']['message_id'];
            $data = $callback['data'];
            $chat_title = self::getChatTitle($callback['message']['chat'] ?? []);
            $thread_id = $callback['message']['message_thread_id'] ?? null;

            TelegramModel::answerCallbackQuery($tokenBot, $callback['id']);

            if ($data === '/search_site')
            {
                // if ($chat_type !== 'private')
                // {
                //     $message = 'Sorry, this command can only be used in private chat.';
                //     self::sendResponse($tokenBot, $chat_id, $thread_id, $messageID, $message, $keyboard);
                //     return response()->json(['success' => true]);
                // }

                self::setUserState($chat_id, ['step' => 'input_site_name']);
                TelegramModel::sendMessage($tokenBot, $chat_id, '🔖 Please enter Site Name');
                return response()->json(['success' => true]);
            }

            $message = self::processCommand($data, $callback['message']['chat'], $callback['message']);

            self::sendResponse($tokenBot, $chat_id, $thread_id, $messageID, $message, $keyboard);
            return response()->json(['success' => true]);
        }
        catch (Exception $e)
        {
            Log::error('Exception in handleCallbackQuery: ' . $e->getMessage());
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    private static function handleMessage($message, $tokenBot, $keyboard)
    {
        try
        {
            $chat_type = $message['chat']['type'] ?? '';
            $chat_id = $message['chat']['id'];
            $messageID = $message['message_id'];
            $text = $message['text'] ?? '';
            $thread_id = $message['message_thread_id'] ?? null;

            $state = self::getUserState($chat_id);
            if ($state && $state['step'] === 'input_site_name' && !empty($text))
            {
                $response = self::searchSite($text);
                self::clearUserState($chat_id);
                self::sendResponse($tokenBot, $chat_id, $thread_id, $messageID, $response, $keyboard);
                return response()->json(['success' => true]);
            }

            if (!empty($text) && substr($text, 0, 1) == '/')
            {
                // if ($chat_type !== 'private' && strpos($text, '/search_site') === 0)
                // {
                //     $message = 'Sorry, this command can only be used in private chat.';
                //     self::sendResponse($tokenBot, $chat_id, $thread_id, $messageID, $message, $keyboard);
                //     return response()->json(['success' => true]);
                // }

                if (strpos($text, '/search_site') === 0)
                {
                    self::setUserState($chat_id, ['step' => 'input_site_name']);
                    TelegramModel::sendMessage($tokenBot, $chat_id, '🔖 Please enter Site Name');
                    return response()->json(['success' => true]);
                }

                $response = self::processCommand($text, $message['chat'], $message);
                self::sendResponse($tokenBot, $chat_id, $thread_id, $messageID, $response, $keyboard);
            }

            return response()->json(['success' => true]);
        }
        catch (Exception $e)
        {
            Log::error('Exception in handleMessage: ' . $e->getMessage());
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    private static function processCommand($command, $chat, $message)
    {
        $chat_title = self::getChatTitle($chat);

        if (strpos($command, '/start') === 0)
        {
            return self::getGreetingMessage($chat_title);
        }

        if (strpos($command, '/clock') === 0)
        {
            $timeNow = date('Y-m-d H:i:s', time());
            return "Current Time : <b>$timeNow</b>";
        }

        if (strpos($command, '/chat_id') === 0)
        {
            $chat_id = $chat['id'];
            $response = "Name       : <b>$chat_title</b>\n";
            $response .= "Chat ID    : <b>$chat_id</b>\n";

            if (isset($message['message_thread_id']))
            {
                $topic_id = $message['message_thread_id'];
                $topic_name = $message['reply_to_message']['forum_topic_created']['name'] ?? '';

                $response .= "\n";
                $response .= "Topic Name : <b>$topic_name</b>\n";
                $response .= "Topic ID   : <b>$topic_id</b>";
            }

            return $response;
        }

        return 'Sorry, command not available.';
    }

    private static function getGreetingMessage($chat_title)
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
        else
        {
            $saying = 'Good Night';
        }

        return "Hello $chat_title, $saying!";
    }

    private static function searchSite($siteName)
    {
        try
        {
            $siteName = trim($siteName);
            if (empty($siteName))
            {
                return "Please enter a valid site name.";
            }

            $data = DB::table('tb_source_mtel')
                ->where('site_name_ne', 'LIKE', "%$siteName%")
                ->first();

            if (!$data)
            {
                return "Site Name <b>$siteName</b> not found.";
            }

            $message[1]  = "<code>";
            $message[1] .= "Workorder      : " . ($data->workorder ?? 'N/A') . "\n";
            $message[1] .= "Ring ID        : " . ($data->ring_id ?? 'N/A') . "\n";
            $message[1] .= "Project ID     : " . ($data->project_id ?? 'N/A') . "\n";
            $message[1] .= "Span ID        : " . ($data->span_id ?? 'N/A') . "\n";
            $message[1] .= "Regional       : " . ($data->regional ?? 'N/A') . "\n";
            $message[1] .= "Witel          : " . ($data->witel ?? 'N/A') . "\n";
            $message[1] .= "STO            : " . ($data->sto ?? 'N/A') . "\n";
            $message[1] .= "Segment Span   : " . ($data->segment_span ?? 'N/A') . "\n\n";
            $message[1] .= "</code>";

            $message[2]  = "<code>";
            $message[2] .= "Site NE        : " . ($data->site_ne ?? 'N/A') . "\n";
            $message[2] .= "Site Name NE   : " . ($data->site_name_ne ?? 'N/A') . "\n";
            $message[2] .= "Site Owner NE  : " . ($data->site_owner_ne ?? 'N/A') . "\n";
            $message[2] .= "Latitude NE    : " . ($data->site_lat_ne ?? 'N/A') . "\n";
            $message[2] .= "Longitude NE   : " . ($data->site_long_ne ?? 'N/A') . "\n\n";
            $message[2] .= "</code>";

            $message[3]  = "<code>";
            $message[3] .= "Site FE        : " . ($data->site_fe ?? 'N/A') . "\n";
            $message[3] .= "Site Name FE   : " . ($data->site_name_fe ?? 'N/A') . "\n";
            $message[3] .= "Site Owner FE  : " . ($data->site_owner_fe ?? 'N/A') . "\n";
            $message[3] .= "Latitude FE    : " . ($data->site_lat_fe ?? 'N/A') . "\n";
            $message[3] .= "Longitude FE   : " . ($data->site_long_fe ?? 'N/A') . "\n\n";
            $message[3] .= "</code>";

            $message[4]  = "<code>";
            $message[4] .= "WO By          : " . ($data->wo_by ?? 'N/A') . "\n";
            $message[4] .= "MNO            : " . ($data->mno ?? 'N/A') . "\n";
            $message[4] .= "Real Kabel (M) : " . ($data->real_kabel_meter ?? 'N/A') . "\n";
            $message[4] .= "Real Kabel (KM): " . ($data->real_kabel_kilometer ?? 'N/A') . "\n";
            $message[4] .= "Over 20KM      : " . ($data->is_over_20km ?? 'N/A') . "\n";
            $message[4] .= "Real Tiang New : " . ($data->real_tiang_new ?? 'N/A') . "\n\n";
            $message[4] .= "</code>";

            $message[5]  = "<code>";
            $message[5] .= "RFS Real       : " . ($data->rfs_real ?? 'N/A') . "\n";
            $message[5] .= "Test Comm      : " . ($data->testcomm ?? 'N/A') . "\n";
            $message[5] .= "Uji Terima     : " . ($data->uji_terima ?? 'N/A') . "\n";
            $message[5] .= "BAST           : " . ($data->bast ?? 'N/A') . "\n";
            $message[5] .= "Last Update    : " . ($data->last_update ?? 'N/A') . "\n";
            $message[5] .= "</code>";

            if (
                !empty($data->site_lat_ne) && !empty($data->site_long_ne) &&
                $data->site_lat_ne !== 'N/A' && $data->site_long_ne !== 'N/A'
            )
            {
                $message['location'] = [
                    'latitude' => $data->site_lat_ne,
                    'longitude' => $data->site_long_ne
                ];
            }

            return $message;
        }
        catch (Exception $e)
        {
            Log::error('Exception in searchSite: ' . $e->getMessage());
            return "Sorry, there was an error searching for the site.";
        }
    }

    private static function sendResponse($tokenBot, $chat_id, $thread_id, $messageID, $message, $keyboard)
    {
        try
        {
            if (is_array($message))
            {
                foreach ($message as $index => $msg)
                {
                    if ($index === 'location')
                    {
                        continue;
                    }

                    if ($thread_id)
                    {
                        TelegramModel::sendMessageReplyThread($tokenBot, $chat_id, $thread_id, $msg, $messageID);
                    }
                    else
                    {
                        TelegramModel::sendMessageReply($tokenBot, $chat_id, $msg, $messageID);
                    }
                    usleep(100000);
                }

                if (isset($message['location']))
                {
                    $latitude = $message['location']['latitude'];
                    $longitude = $message['location']['longitude'];
                    TelegramModel::sendLocation($tokenBot, $chat_id, $latitude, $longitude);
                    usleep(100000);
                }

                if (!$thread_id)
                {
                    TelegramModel::sendMessageReplyMarkupKeyboardWithReply($tokenBot, $chat_id, "Select an option:", $messageID, $keyboard);
                }
            }
            else
            {
                if ($thread_id)
                {
                    TelegramModel::sendMessageReplyThread($tokenBot, $chat_id, $thread_id, $message, $messageID);
                }
                else
                {
                    TelegramModel::sendMessageReplyMarkupKeyboardWithReply($tokenBot, $chat_id, $message, $messageID, $keyboard);
                }
            }
        }
        catch (Exception $e)
        {
            Log::error('Exception in sendResponse: ' . $e->getMessage());
        }
    }

    private static function getUserState($chat_id)
    {
        $file = storage_path("app/user_state_$chat_id.json");
        try
        {
            if (file_exists($file))
            {
                $content = file_get_contents($file);
                if ($content === false)
                {
                    Log::warning("Failed to read user state file for chat_id: $chat_id");
                    return null;
                }
                return json_decode($content, true);
            }
        }
        catch (Exception $e)
        {
            Log::error("Exception reading user state for chat_id $chat_id: " . $e->getMessage());
        }

        return null;
    }

    private static function setUserState($chat_id, $data)
    {
        $file = storage_path("app/user_state_$chat_id.json");
        try
        {
            $directory = dirname($file);
            if (!is_dir($directory))
            {
                mkdir($directory, 0755, true);
            }

            $result = file_put_contents($file, json_encode($data));
            if ($result === false)
            {
                Log::warning("Failed to write user state file for chat_id: $chat_id");
            }
        }
        catch (Exception $e)
        {
            Log::error("Exception setting user state for chat_id $chat_id: " . $e->getMessage());
        }
    }

    private static function clearUserState($chat_id)
    {
        $file = storage_path("app/user_state_$chat_id.json");
        try
        {
            if (file_exists($file))
            {
                $result = unlink($file);
                if (!$result)
                {
                    Log::warning("Failed to delete user state file for chat_id: $chat_id");
                }
            }
        }
        catch (Exception $e)
        {
            Log::error("Exception clearing user state for chat_id $chat_id: " . $e->getMessage());
        }
    }

    private static function getChatTitle($chat)
    {
        if (empty($chat))
        {
            return 'Unknown';
        }

        if (isset($chat['title']) && !empty($chat['title']))
        {
            return htmlspecialchars($chat['title'], ENT_QUOTES, 'UTF-8');
        }

        $name = '';
        if (isset($chat['first_name']) && !empty($chat['first_name']))
        {
            $name = $chat['first_name'];
        }

        if (isset($chat['last_name']) && !empty($chat['last_name']))
        {
            $name .= ' ' . $chat['last_name'];
        }

        return !empty($name) ? htmlspecialchars(trim($name), ENT_QUOTES, 'UTF-8') : 'Unknown';
    }
}
