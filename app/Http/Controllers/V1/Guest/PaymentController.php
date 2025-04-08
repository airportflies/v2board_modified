<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) abort(500, 'verify error');
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                abort(500, 'handle error');
            }
            return(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            abort(500, 'fail');
        }
    }

    private function handle($tradeNo, $callbackNo)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            abort(500, 'order is not found');
        }
        if ($order->status !== 0) return true;
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }
        $payment = Payment::where('id', $order->payment_id)->first();
        $user = User::where('id', $order->user_id)->first();
        
        $telegramService = new TelegramService();
        $message = sprintf(
          "💰成功收款%s元\n———————————————\n用户邮箱：`%s`\n支付接口：%s\n支付渠道：%s\n订单号：`%s`",
          $order->total_amount / 100,
          $user->email ?? '未知邮箱',
          $payment->payment ?? '未知接口',
          $payment->name ?? '未知渠道',
          $order->trade_no
        );
        $telegramService->sendMessageWithAdmin($message);
        $url = env('ORDER_REPORTURL', 'http://127.0.0.1:29991/order');
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
        return true;
    }
}
