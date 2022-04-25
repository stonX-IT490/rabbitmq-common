<?php
    require_once __DIR__ . '/vendor/autoload.php';
    include('config.php')
    use PhpAmqpLib\Connection\AMQPStreamConnection;
    use PhpAmqpLib\Message\AMQPMessage;

    function getConnection(){
        $host = $config['host']
        $port = $config['port']
        $username = $config['username']
        $password = $config['password']
        $vhost = $config['vhost']
        return $connection = new AMQPStreamConnection($host, $port, $username, $password);
    }

    function sendMessage($queue, $message){
        $channel = getConnection()
        $channel = $connection->channel();
        $channel->queue_declare($queue, false, false, false, false);
        $msg = new AMQPMessage($message);
        $channel->basic_publish($msg, '', $queue);
        $channel->close();
        $connection->close();
    }
    function loginRequest($username, $password){
        $channel = getConnection();
        $channel = $connection->channel();
        $queue ='login'
        $channel->queue_declare($queue, false, false, false, false);
        $msg = new AMQPMessage($username+','+$password);
        $channel->basic_publish($msg, '', $queue);
        $channel->close();
        $connection->close();
    }
    function loginResponse($callback){
        $channel = getConnection();
        $channel = $connection->channel();
        $queue ='login'
        $channel->queue_declare($queue, false, false, false, false);
        $channel->basic_consume($queue, '', false, true, false, false, $callback);
        return $channel
    }
?>
