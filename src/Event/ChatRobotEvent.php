<?php
/**
 * @desc DingTalkRobotEvent.php 钉钉机器人
 * @author Tinywan(ShaoBo Wan)
 * @date 2022/3/21 13:55
 */

declare(strict_types=1);

namespace Inked7\ExceptionHandler\Event;

class ChatRobotEvent
{
    /**
     * 发送企业微信机器人
     * @param array $args
     * @param array $config
     * @param string $name
     * @return bool|string
     */
    public static function chatRobot(array $args, array $config, string $name = '')
    {
        $config =  $config['event_trigger']['chat'];
        $accessToken = $config['accessToken'];
        $title = $config['title'];
        $message = ' - <font color="#dd00dd">监控来源： ' .$title. "</font> \n";
        if (!empty($name)) {
            $title = $name;
            $message = ' - <font color="#dd0000">监控来源： ' .$title. "</font> \n";
        }
        $message .= ' - 响应错误： ' .$args['message']. " \n";
        $message .= ' - 详细错误：' . $args['error'] . " \n";
        $message .= ' - 请求域名：' . $args['domain'] ?? '__' . " \n";
        $message .= ' - 请求路由：' . $args['request_url'] . " \n";
        $message .= ' - 请求IP：' . $args['client_ip'] . " \n";
        $message .= ' - 请求时间：' . $args['timestamp'] . " \n";
        $message .= ' - 请求参数：' . json_encode($args['request_param']) . " \n";
        $message .= ' - 异常文件：' . $args['file'] . " \n";
        $message .= ' - 异常文件行数：' . $args['line'] . " \n";
        $data = [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => $message
            ],
            'at' => [
                'isAtAll' => false
            ]
        ];
//        $orderPayUrl = 'https://oapi.dingtalk.com/robot/send?access_token=' . $accessToken;
        $chatUrl = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key='. $accessToken;
        return  self::request_by_curl($chatUrl, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @desc: 自定义请求类
     * @param string $remote_server
     * @param string $postString
     * @return bool|string
     * @author Tinywan(ShaoBo Wan)
     */
    private static function request_by_curl(string $remote_server, string $postString)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $remote_server);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json;charset=utf-8'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postString);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }
}
