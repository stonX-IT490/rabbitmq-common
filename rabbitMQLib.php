<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;


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
		$body = $req->body;
		$payload = json_decode($body, true); 
		$response;
		
		if (isset($this->callback)) {
			
			$response = new AMQPMessage(
				json_encode( call_user_func($this->callback, $payload) ), //Call the function callback, with parameter(s) in $payload
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
		$this->channel->basic_consume($this->queue, '', false, false, false, false, array($this, 'consume_request')); //This means that messages will be sent to function consume_request

		while ($this->channel->is_open()) {
			$this->channel->wait();
		}

		$this->channel->close();
		$this->connection->close();
	}
}
//test code:
//$testConsumer = new rabbitMQConsumer("amq.direct", "rpc_queue");
//$testConsumer->process_requests('fib');

class rabbitMQProducer
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
    private $connection;
    private $channel;
    private $callback_queue;
    private $response;
    private $corr_id;

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
      
        $this->connection = new AMQPStreamConnection($this->host, $this->port, $this->username, $this->password, $this->vhost);
        $this->channel = $this->connection->channel();
        list($this->callback_queue, ,) = $this->channel->queue_declare(
            "",
            false,
            false,
            true,
            false
        );
        $this->channel->basic_consume(
            $this->callback_queue,
            '',
            false,
            true,
            false,
            false,
            array(
                $this,
                'onResponse'
            )
        );
    }

    public function onResponse($rep)
    {
        if ($rep->get('correlation_id') == $this->corr_id) {
            $this->response = $rep->body;
        }
    }

    public function send_request($n)
    {
        $this->response = null;
        $this->corr_id = uniqid();

        $msg = new AMQPMessage(
            (string) json_encode($n),
            array(
                'correlation_id' => $this->corr_id,
                'reply_to' => $this->callback_queue
            )
        );
        $this->channel->basic_publish($msg, '', $this->queue);
        while (!$this->response) {
            $this->channel->wait();
        }
        return json_decode($this->response, true);
    }
}


//$client = new rabbitMQProducer('amq.direct', 'news');
//$response = $client->send_request('Aman');

?>
