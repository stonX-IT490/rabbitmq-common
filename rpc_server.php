<?php

//include('config.php');
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function fib($n)
{
        return $n."!";
}

class rabbitMQConsumer
{
        public $host;
        private $port;
        private $username;
        private $password;
        private $vhost;
        private $exchange;
        private $queue;
        private $routing_key = '*';
        private $exchange_type = "direct";
        
        //Initialize the consumer
        public function __construct($exchange, $queue)
        {
                require_once __DIR__ . "/config.php"; //pull in our credentials

                $this->host = $config['host'];
                $this->port = $config['port'];
                $this->username = $config['username'];
                $this->password = $config['password'];
                $this->vhost = $config['vhost'];
                
                if (isset($config["exchange_type"])) {
                        $this->exchange_type = $config["exchange_type"];
                }
                
                $this->exchange = $exchange;
                $this->queue = $queue;
                
        }
        
        public function consume_request($req)
        {
//              $this->callback = $callback;
                $body = $req->body;
                //$payload = json_decode($body, true); 
                $response;
                
                if (isset($this->callback)) {
                        
                        $response = new AMQPMessage(
                                call_user_func($this->callback, $body), //Call the function callback, with parameter(s) in $paylo>
                                array('correlation_id' => $req->get('correlation_id')) //"return to sender using correlation_id"
                        );
                        
                }

                $req->delivery_info['channel']->basic_publish(
                        $response,
                        '',
                        $req->get('reply_to')
                );
                $req->ack();
                
        }
        
        public function process_requests($callback)
        {
                $this->callback = $callback;
                $this->connection = new AMQPStreamConnection($this->host, $this->port, $this->username, $this->password, $this->vhost);
                $this->channel = $this->connection->channel();

                $this->channel->queue_declare($this->queue, false, false, false, false);

                $this->channel->basic_qos(null, 1, null);
                $this->channel->basic_consume($this->queue, '', false, false, false, false, array($this, 'consume_request')); //T>

                while ($this->channel->is_open()) {
                        $this->channel->wait();
                }

                $this->channel->close();
                $this->connection->close();
        }
}

$testConsumer = new rabbitMQConsumer("amq.direct", "rpc_queue");
$testConsumer->process_requests('fib');

?>
