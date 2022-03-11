<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class rabbitMQConsumer {
  public $BROKER_HOST;
  private $BROKER_PORT;
  private $USER;
  private $PASSWORD;
  private $VHOST;
  private $exchange;
  private $queue;
  private $routing_key = '*';
  private $exchange_type = "topic";
  private $auto_delete = true;

  function __construct($exchange, $queue) {
    require_once __DIR__ . "/config.php"; //pull in our credentials

    $this->BROKER_HOST = $config['host'];
    $this->BROKER_PORT = $config['port'];
    $this->USER = $config['username'];
    $this->PASSWORD = $config['password'];
    $this->VHOST = $config['vhost'];
    if (isset($config["exchange_type"])) {
      $this->exchange_type = $config["exchange_type"];
    }
    if (isset($config["auto_delete"])) {
      $this->auto_delete = $config["exchange_type"];
    }
    $this->exchange = $exchange;
    $this->queue = $queue;
  }

  function process_message($msg) {
    // send the ack to clear the item from the queue
    if ($msg->getRoutingKey() !== "*") {
      return;
    }
    $this->conn_queue->ack($msg->getDeliveryTag());
    try {
      if ($msg->getReplyTo()) {
        // message wants a response
        // process request
        $body = $msg->getBody();
        $payload = json_decode($body, true);
        $response;
        if (isset($this->callback)) {
          $response = call_user_func($this->callback, $payload);
        }

        $params = [];
        $params['host'] = $this->BROKER_HOST;
        $params['port'] = $this->BROKER_PORT;
        $params['login'] = $this->USER;
        $params['password'] = $this->PASSWORD;
        $params['vhost'] = $this->VHOST;
        $conn = new AMQPConnection($params);
        $conn->connect();
        $channel = new AMQPChannel($conn);
        $exchange = new AMQPExchange($channel);
        $exchange->setName($this->exchange);
        $exchange->setType($this->exchange_type);

        $conn_queue = new AMQPQueue($channel);
        $conn_queue->setName($msg->getReplyTo());
        $replykey = $this->routing_key . ".response";
        $conn_queue->bind($exchange->getName(), $replykey);
        $exchange->publish(json_encode($response), $replykey, AMQP_NOPARAM, ['correlation_id' => $msg->getCorrelationId()]);

        return;
      }
    } catch (Exception $e) {
      // ampq throws exception if get fails...
      echo "error: rabbitMQServer: process_message: exception caught: " . $e;
    }
    // message does not require a response, send ack immediately
    $body = $msg->getBody();
    $payload = json_decode($body, true);
    if (isset($this->callback)) {
      call_user_func($this->callback, $payload);
    }
    echo "processed one-way message\n";
  }

  function process_requests($callback) {
    try {
      $this->callback = $callback;
      $params = [];
      $params['host'] = $this->BROKER_HOST;
      $params['port'] = $this->BROKER_PORT;
      $params['login'] = $this->USER;
      $params['password'] = $this->PASSWORD;
      $params['vhost'] = $this->VHOST;
      $conn = new AMQPConnection($params);
      $conn->connect();

      $channel = new AMQPChannel($conn);

      $exchange = new AMQPExchange($channel);
      $exchange->setName($this->exchange);
      $exchange->setType($this->exchange_type);

      $this->conn_queue = new AMQPQueue($channel);
      $this->conn_queue->setName($this->queue);
      $this->conn_queue->bind($exchange->getName(), $this->routing_key);

      $this->conn_queue->consume([$this, 'process_message']);

      // Loop as long as the channel has callbacks registered
      while (count($channel->callbacks)) {
        $channel->wait();
      }
    } catch (Exception $e) {
      trigger_error("Failed to start request processor: " . $e, E_USER_ERROR);
    }
  }
}

class rabbitMQProducer {
  public $BROKER_HOST;
  private $BROKER_PORT;
  private $USER;
  private $PASSWORD;
  private $VHOST;
  private $exchange;
  private $queue;
  private $routing_key = '*';
  private $response_queue = [];
  private $exchange_type = "topic";

  function __construct($exchange, $queue) {
    require_once __DIR__ . "/config.php"; //pull in our credentials

    $this->BROKER_HOST = $config['host'];
    $this->BROKER_PORT = $config['port'];
    $this->USER = $config['username'];
    $this->PASSWORD = $config['password'];
    $this->VHOST = $config['vhost'];
    if (isset($config["exchange_type"])) {
      $this->exchange_type = $config["exchange_type"];
    }
    $this->exchange = $exchange;
    $this->queue = $queue;
  }

  function process_response($response) {
    $uid = $response->getCorrelationId();
    if (!isset($this->response_queue[$uid])) {
      echo "unknown uid\n";
      return true;
    }
    $this->conn_queue->ack($response->getDeliveryTag());
    $body = $response->getBody();
    $payload = json_decode($body, true);
    if (!isset($payload)) {
      $payload = "[empty response]";
    }
    $this->response_queue[$uid] = $payload;
    return false;
  }

  function send_request($message) {
    $uid = uniqid();

    $json_message = json_encode($message);
    try {
      $params = [];
      $params['host'] = $this->BROKER_HOST;
      $params['port'] = $this->BROKER_PORT;
      $params['login'] = $this->USER;
      $params['password'] = $this->PASSWORD;
      $params['vhost'] = $this->VHOST;

      $conn = new AMQPConnection($params);
      $conn->connect();

      $channel = new AMQPChannel($conn);

      $exchange = new AMQPExchange($channel);
      $exchange->setName($this->exchange);
      $exchange->setType($this->exchange_type);

      $callback_queue = new AMQPQueue($channel);
      $callback_queue->setName($this->queue . "_response");
      $callback_queue->declare();
      $callback_queue->bind($exchange->getName(), $this->routing_key . ".response");

      $this->conn_queue = new AMQPQueue($channel);
      $this->conn_queue->setName($this->queue);
      $this->conn_queue->bind($exchange->getName(), $this->routing_key);

      $exchange->publish($json_message, $this->routing_key, AMQP_NOPARAM, ['reply_to' => $callback_queue->getName(), 'correlation_id' => $uid]);
      $this->response_queue[$uid] = "waiting";
      $callback_queue->consume([$this, 'process_response']);

      $response = $this->response_queue[$uid];
      unset($this->response_queue[$uid]);
      return $response;
    } catch (Exception $e) {
      fwrite(STDERR, "failed to send message to exchange: " . $e->getMessage() . "\n");
      var_export($e);
    }
  }

  function publish($message) {
    $json_message = json_encode($message);
    try {
      $params = [];
      $params['host'] = $this->BROKER_HOST;
      $params['port'] = $this->BROKER_PORT;
      $params['login'] = $this->USER;
      $params['password'] = $this->PASSWORD;
      $params['vhost'] = $this->VHOST;
      $conn = new AMQPConnection($params);
      $conn->connect();
      $channel = new AMQPChannel($conn);
      $exchange = new AMQPExchange($channel);
      $exchange->setName($this->exchange);
      $exchange->setType($this->exchange_type);
      $this->conn_queue = new AMQPQueue($channel);
      $this->conn_queue->setName($this->queue);
      $this->conn_queue->bind($exchange->getName(), $this->routing_key);
      return $exchange->publish($json_message, $this->routing_key);
    } catch (Exception $e) {
      fwrite(STDERR, "failed to send message to exchange: " . $e->getMessage() . "\n");
      var_export($e);
    }
  }
}

?>
