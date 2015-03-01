<?php
use Workerman\Worker;

/*******************************************************************
 * 基于Worker实现的一个简单的消息队列服务
 * 服务两组进程，
 * 一组监听端口并把发来的数据放到sysv消息队列中
 * 另外一组进程为消费者，负责从队列中读取数据并处理
 * 
 * 注意：
 * 使用的是系统自带的 sysv 队列，即使队列服务重启数据也不会丢失
 * 但服务器重启后数据会丢失
 *******************************************************************/

// 队列的id。为了避免混淆，可以和监听的端口相同
$QUEUE_ID = 1236;

// #######消息队列服务监听的端口##########
$msg_recver = new Worker('Text://0.0.0.0:1236');
// 向哪个队列放数据
$msg_recver->queueId = $QUEUE_ID;

/**
 * 进程启动时，初始化sysv消息队列
 */
$msg_recver->onWorkerStart = function($msg_recver)
{
    if(!extension_loaded('sysvmsg'))
    {
        echo "Please install sysvmsg extension.\n";
        return;
    }
    $msg_recver->queue = msg_get_queue($msg_recver->queueId);
};

/**
 * 服务接收到消息时，将消息写入系统的sysv消息队列，消费者从该队列中读取
 */
$msg_recver->onMessage = function($connection, $message) use ($msg_recver)
{
    $msgtype = 1;
    $errorcode = 0;
    // @see http://php.net/manual/zh/function.msg-send.php
    if(msg_send( $msg_recver->queue , $msgtype , $message, true , true , $errorcode))
    {
        return $connection->send('{"code":0, "msg":"success"}');
    }
    else 
    {
        return $connection->send('{"code":500, "msg":"'.$errorcode.'"}');
    }
};


// ######## 消息队列消费者 ########
$consumer = new Worker();
// 消费的队列的id
$consumer->queueId = $QUEUE_ID;

/**
 * 进程启动阻塞式的从队列中读取数据并处理
 */
$consumer->onWorkerStart = function($consumer)
{
    if(extension_loaded('sysvmsg'))
    {
        // 获得队列资源
        $consumer->queue = msg_get_queue($consumer->queueId);
        while(1)
        {
            $desiredmsgtype = 1;
            $msgtype = 0;
            $message = '';
            $maxsize = 65535;
            // 从队列中获取消息 @see http://php.net/manual/zh/function.msg-receive.php
            msg_receive($consumer->queue , $desiredmsgtype , $msgtype , $maxsize , $message);
             // 假设消息数据为json，格式为{"class":"class_name", "method":"method_name", "args":"args_array"}
             $message = json_decode($message, true);
             // 格式如果是正确的，则尝试执行对应的类方法
             if(isset($message['class']) && isset($message['method']) && isset($message['args']))
             {
                 // 要调用的类名，加上Consumer命名空间
                 $class_name = "\\Consumer\\".$message['class'];
                 // 要调用的方法名
                 $method = $message['method'];
                 // 调用参数，是个数组
                 $args = (array)$message['args'];
                 
                 // 类存在则尝试执行
                 if(class_exists($class_name))
                 {
                     $class = new $class_name;
                     $callback = array($class, $method);
                     if(is_callable($callback))
                     {
                         call_user_func_array($callback, $args);
                     }
                     else
                     {
                         echo "$class_name::$method not exist\n";
                     }
                 }
                 else
                 {
                     echo "$class_name not exist\n";
                 }
             }
        }
    }
};