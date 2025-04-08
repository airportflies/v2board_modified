<?php
namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use \Curl\Curl;
use Illuminate\Mail\Markdown;

class TelegramService {
    protected $api;

    public function __construct($token = '')
    {
        $this->api = 'https://api.telegram.org/bot' . config('v2board.telegram_bot_token', $token) . '/';
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = '')
    {
        if ($parseMode === 'markdown') {
            $text = str_replace('_', '\_', $text);
        }
        $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode
        ]);
    }

    public function approveChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function declineChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function getMe()
    {
        return $this->request('getMe');
    }

    public function setWebhook(string $url)
    {
        return $this->request('setWebhook', [
            'url' => $url
        ]);
    }

    private function request(string $method, array $params = [])
    {
        $curl = new Curl();
        $curl->get($this->api . $method . '?' . http_build_query($params));
        $response = $curl->response;
        $curl->close();
        if (!isset($response->ok)) abort(500, '请求失败');
        if (!$response->ok) {
            abort(500, '来自TG的错误：' . $response->description);
        }
        return $response;
    }

    public function sendMessageWithAdmin($message, $isStaff = false)
    {
        $url = env('REPORTURL', 'http://127.0.0.1:29991/reporter');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url); // 设置请求的 URL
        curl_setopt($ch, CURLOPT_POST, true); // 设置为 POST 请求
        curl_setopt($ch, CURLOPT_POSTFIELDS, $message); // 设置 POST 数据
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // 不需要返回响应内容
        curl_setopt($ch, CURLOPT_HEADER, false); // 不需要返回头信息
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1); // 设置连接超时时间为 1 秒
        curl_setopt($ch, CURLOPT_TIMEOUT, 1); // 设置请求超时时间为 1 秒
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true); // 强制使用新连接
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true); 
        curl_exec($ch);
        curl_close($ch);
        if (!config('v2board.telegram_bot_enable', 0)) return;
        $users = User::where(function ($query) use ($isStaff) {
            $query->where('is_admin', 1);
            if ($isStaff) {
                $query->orWhere('is_staff', 1);
            }
        })
            ->where('telegram_id', '!=', NULL)
            ->get();
        foreach ($users as $user) {
            SendTelegramJob::dispatch($user->telegram_id, $message);
        }
    }
}
