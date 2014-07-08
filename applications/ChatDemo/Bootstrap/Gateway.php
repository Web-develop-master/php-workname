<?php
/**
 * 
 * 暴露给客户端的连接网关 只负责网络io
 * 1、监听客户端连接
 * 2、监听后端回应并转发回应给前端
 * 
 * @author walkor <workerman.net>
 * 
 */
define('ROOT_DIR', realpath(__DIR__.'/../'));
require_once ROOT_DIR . '/Protocols/GatewayProtocol.php';
require_once ROOT_DIR . '/Lib/Store.php';

class Gateway extends Man\Core\SocketWorker
{
    /**
     * 内部通信socket udp
     * @var resouce
     */
    protected $innerMainSocketUdp = null;
    
    /**
     * 内部通信socket tcp
     * @var resouce
     */
    protected $innerMainSocketTcp = null;
    
    /**
     * 内网ip
     * @var string
     */
    protected $lanIp = '127.0.0.1';
    
    /**
     * 内部通信端口
     * @var int
     */
    protected $lanPort = 0;
    
    /**
     * uid到连接的映射
     * @var array
     */
    protected $uidConnMap = array();
    
    /**
     * 连接到uid的映射
     * @var array
     */
    protected $connUidMap = array();
    
    /**
     * 与worker的连接
     * [fd=>fd, $fd=>fd, ..]
     * @var array
     */
    protected $workerConnections = array();
    
    /**
     * gateway 发送心跳时间间隔 单位：秒 ,0表示不发送心跳，在配置中设置
     * @var integer
     */
    protected $pingInterval = 0;
    
    /**
     * 心跳数据
     * 可以是字符串（在配置中直接设置字符串如 ping_data=ping），
     * 可以是二进制数据(二进制数据保存在文件中，在配置中设置ping数据文件路径 如 ping_data=/yourpath/ping.bin)
     * ping数据应该是客户端能够识别的数据格式，只是检测连接的连通性，客户端收到心跳数据可以选择忽略此数据包
     * @var string
     */
    protected $pingData = '';
    
    /**
     * 由于网络延迟或者socket缓冲区大小的限制，客户端发来的数据可能不会都全部到达，需要根据协议判断数据是否完整
     * @see Man\Core.SocketWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        // 这个聊天demo发送数据量都很小，一般都小于一个ip数据包，所以没有判断长度，直接返回了0，表示数据全部到达
        // 其它应用应该根据客户端协议来判断数据是否完整
        return 0;
    }
    
    /**
     * 用户客户端发来消息时处理
     * @see Man\Core.SocketWorker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        // 判断用户是否认证过
        $from_uid = $this->getUidByFd($this->currentDealFd);
        // 触发ON_CONNECTION
        if(!$from_uid)
        {
            return $this->sendToWorker(GatewayProtocol::CMD_ON_CONNECTION, $this->currentDealFd, $recv_str);
        }
    
        // 认证过, 触发ON_MESSAGE
        $this->sendToWorker(GatewayProtocol::CMD_ON_MESSAGE, $this->currentDealFd, $recv_str);
    }
    
    /**
     * 进程启动
     */
    public function start()
    {
        // 安装信号处理函数
        $this->installSignal();
        
        // 添加accept事件
        $ret = $this->event->add($this->mainSocket,  Man\Core\Events\BaseEvent::EV_READ, array($this, 'accept'));
        
        // 创建内部通信套接字，用于与BusinessWorker通讯
        $start_port = Man\Core\Lib\Config::get($this->workerName.'.lan_port_start');
        // 计算本进程监听的ip端口
        $this->lanPort = $start_port - posix_getppid() + posix_getpid();
        $this->lanIp = Man\Core\Lib\Config::get($this->workerName.'.lan_ip');
        if(!$this->lanIp)
        {
            $this->notice($this->workerName.'.lan_ip not set');
            $this->lanIp = '127.0.0.1';
        }
        $error_no_udp = $error_no_tcp = 0;
        $error_msg_udp = $error_msg_tcp = '';
        // 执行监听
        $this->innerMainSocketUdp = stream_socket_server("udp://".$this->lanIp.':'.$this->lanPort, $error_no_udp, $error_msg_udp, STREAM_SERVER_BIND);
        $this->innerMainSocketTcp = stream_socket_server("tcp://".$this->lanIp.':'.$this->lanPort, $error_no_tcp, $error_msg_tcp, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        // 出错，退出，下次会换个端口
        if(!$this->innerMainSocketUdp || !$this->innerMainSocketTcp)
        {
            $this->notice('create innerMainSocket udp or tcp fail and exit '.$error_msg_udp.$error_msg_tcp);
            $this->stop();
        }
        else
        {
            stream_set_blocking($this->innerMainSocketUdp , 0);
            stream_set_blocking($this->innerMainSocketTcp , 0);
        }
        
        // 注册套接字
        $this->registerAddress($this->lanIp.':'.$this->lanPort);
        
        // 添加读udp/tcp事件
        $this->event->add($this->innerMainSocketUdp,  Man\Core\Events\BaseEvent::EV_READ, array($this, 'recvUdp'));
        $this->event->add($this->innerMainSocketTcp,  Man\Core\Events\BaseEvent::EV_READ, array($this, 'acceptTcp'));
        
        // 初始化心跳包时间间隔
        $ping_interval = \Man\Core\Lib\Config::get($this->workerName.'.ping_interval');
        if((int)$ping_interval > 0)
        {
            $this->pingInterval = (int)$ping_interval;
        }
        
        // 获取心跳包数据
        $ping_data_or_path = \Man\Core\Lib\Config::get($this->workerName.'.ping_data');
        if(is_file($ping_data_or_path))
        {
            $this->pingData = file_get_contents($ping_data_or_path);
        }
        else
        {
            $this->pingData = $ping_data_or_path;
        }
        
        // 设置定时任务，发送心跳
        if($this->pingInterval > 0 && $this->pingData)
        {
            \Man\Core\Lib\Task::init($this->event);
            \Man\Core\Lib\Task::add($this->pingInterval, array($this, 'ping'));
        }
        
        // 主体循环,整个子进程会阻塞在这个函数上
        $ret = $this->event->loop();
        // 下面正常不会执行到
        $this->notice('worker loop exit');
        // 执行到就退出
        exit(0);
    }
    
    /**
     * 存储全局的通信地址 
     * @param string $address
     */
    protected function registerAddress($address)
    {
        // 这里使用了信号量只能实现单机互斥，分布式互斥需要借助于memcache incr 或者其他分布式存储
        \Man\Core\Lib\Mutex::get();
        $key = 'GLOBAL_GATEWAY_ADDRESS';
        $addresses_list = Store::get($key);
        if(empty($addresses_list))
        {
            $addresses_list = array();
        }
        $addresses_list[$address] = $address;
        Store::set($key, $addresses_list);
        \Man\Core\Lib\Mutex::release();
    }
    
    /**
     * 删除全局的通信地址
     * @param string $address
     */
    protected function unregisterAddress($address)
    {
        // 这里使用了信号量只能实现单机互斥，分布式互斥需要借助于memcache incr 或者其他分布式存储
        \Man\Core\Lib\Mutex::get();
        $key = 'GLOBAL_GATEWAY_ADDRESS';
        $addresses_list = Store::get($key);
        if(empty($addresses_list))
        {
            $addresses_list = array();
        }
        unset($addresses_list[$address]);
        Store::set($key, $addresses_list);
        \Man\Core\Lib\Mutex::release();
    }
    
    /**
     * 接收Udp数据
     * 如果数据超过一个udp包长，需要业务自己解析包体，判断数据是否全部到达
     * @param resource $socket
     * @param $null_one $flag
     * @param $null_two $base
     * @return void
     */
    public function recvUdp($socket, $null_one = null, $null_two = null)
    {
        $data = stream_socket_recvfrom($socket , self::MAX_UDP_PACKEG_SIZE, 0, $address);
        // 惊群效应
        if(false === $data || empty($address))
        {
            return false;
        }
         
        $this->currentClientAddress = $address;
       
        $this->innerDealProcess($data);
    }
    
    /**
     * 内部通讯端口接受BusinessWorker连接请求，以便建立起长连接
     * @param resouce $socket
     * @param null $null_one
     * @param null $null_two
     */
    public function acceptTcp($socket, $null_one = null, $null_two = null)
    {
        // 获得一个连接
        $new_connection = @stream_socket_accept($socket, 0);
        if(false === $new_connection)
        {
            return false;
        }
    
        // 连接的fd序号
        $fd = (int) $new_connection;
        $this->connections[$fd] = $new_connection;
        $this->recvBuffers[$fd] = array('buf'=>'', 'remain_len'=>GatewayProtocol::HEAD_LEN);
    
        // 非阻塞
        stream_set_blocking($this->connections[$fd], 0);
        $this->event->add($this->connections[$fd], \Man\Core\Events\BaseEvent::EV_READ , array($this, 'recvTcp'), $fd);
        // 标记这个连接是内部通讯长连接，区别于客户端连接
        $this->workerConnections[$fd] = $fd;
        return $new_connection;
    }
    
    /**
     * 内部通讯判断数据是否全部到达
     * @param string $buffer
     */
    public function dealInnerInput($buffer)
    {
        return GatewayProtocol::input($buffer);
    }
    
    /**
     * 处理受到的数据
     * @param event_buffer $event_buffer
     * @param int $fd
     * @return void
     */
    public function recvTcp($connection, $flag, $fd = null)
    {
        $this->currentDealFd = $fd;
        $buffer = stream_socket_recvfrom($connection, $this->recvBuffers[$fd]['remain_len']);
        // 出错了
        if('' == $buffer && '' == ($buffer = fread($connection, $this->recvBuffers[$fd]['remain_len'])))
        {
            if(!feof($connection))
            {
                return;
            }
            
            // 如果该链接对应的buffer有数据，说明发生错误
            if(!empty($this->recvBuffers[$fd]['buf']))
            {
                $this->statusInfo['send_fail']++;
                $this->notice("INNER_CLIENT_CLOSE\nCLIENT_IP:".$this->getRemoteIp()."\nBUFFER:[".var_export($this->recvBuffers[$fd]['buf'],true)."]\n");
            }
    
            // 关闭链接
            $this->closeInnerClient($fd);
            if($this->workerStatus == self::STATUS_SHUTDOWN)
            {
                $this->stop();
            }
            return;
        }
    
        $this->recvBuffers[$fd]['buf'] .= $buffer;
    
        $remain_len = $this->dealInnerInput($this->recvBuffers[$fd]['buf']);
        // 包接收完毕
        if(0 === $remain_len)
        {
            // 执行处理
            try{
                // 内部通讯业务处理
                $this->innerDealProcess($this->recvBuffers[$fd]['buf']);
            }
            catch(\Exception $e)
            {
                $this->notice('CODE:' . $e->getCode() . ' MESSAGE:' . $e->getMessage()."\n".$e->getTraceAsString()."\nCLIENT_IP:".$this->getRemoteIp()."\nBUFFER:[".var_export($this->recvBuffers[$fd]['buf'],true)."]\n");
                $this->statusInfo['throw_exception'] ++;
            }
            $this->recvBuffers[$fd] = array('buf'=>'', 'remain_len'=>$this->prereadLength);
        }
        // 出错
        else if(false === $remain_len)
        {
            // 出错
            $this->statusInfo['packet_err']++;
            $this->notice("INNER_PACKET_ERROR\nCLIENT_IP:".$this->getRemoteIp()."\nBUFFER:[".var_export($this->recvBuffers[$fd]['buf'],true)."]\n");
            $this->closeInnerClient($fd);
        }
        else
        {
            $this->recvBuffers[$fd]['remain_len'] = $remain_len;
        }
    
        // 检查是否是关闭状态或者是否到达请求上限
        if($this->workerStatus == self::STATUS_SHUTDOWN )
        {
            // 停止服务
            $this->stop();
            // EXIT_WAIT_TIME秒后退出进程
            pcntl_alarm(self::EXIT_WAIT_TIME);
        }
    }

    /**
     * 内部通讯处理
     * @param string $recv_str
     */
    public function innerDealProcess($recv_str)
    {
        $pack = new GatewayProtocol($recv_str);
        
        switch($pack->header['cmd'])
        {
            case GatewayProtocol::CMD_SEND_TO_ONE:
                return $this->sendToSocketId($pack->header['socket_id'], $pack->body);
            case GatewayProtocol::CMD_KICK:
                if($pack->body)
                {
                    $this->sendToSocketId($pack->header['socket_id'], $pack->body);
                }
                return $this->closeClientBySocketId($pack->header['socket_id']);
            case GatewayProtocol::CMD_SEND_TO_ALL:
                return $this->broadCast($pack->body);
            case GatewayProtocol::CMD_CONNECT_SUCCESS:
                return $this->connectSuccess($pack->header['socket_id'], $pack->header['uid']);
            default :
                $this->notice('gateway inner pack cmd err data:' .$recv_str );
        }
    }
    
    /**
     * 广播数据
     * @param string $bin_data
     */
    protected function broadCast($bin_data)
    {
        foreach($this->uidConnMap as $uid=>$conn)
        {
            $this->sendToSocketId($conn, $bin_data);
        }
    }
    
    /**
     * 根据socket_id关闭与客户端的连接，实际上是踢人操作
     * @param int $socket_id
     */
    protected function closeClientBySocketId($socket_id)
    {
        if($uid = $this->getUidByFd($socket_id))
        {
            unset($this->uidConnMap[$uid], $this->connUidMap[$socket_id]);
        }
        parent::closeClient($socket_id);
    }
    
    /**
     * 根据uid获取uid对应连接的id
     * @param int $uid
     */
    protected function getFdByUid($uid)
    {
        if(isset($this->uidConnMap[$uid]))
        {
            return $this->uidConnMap[$uid];
        }
        return 0;
    }
    
    /**
     * 根据连接id获取用户uid
     * @param int $fd
     */
    protected function getUidByFd($fd)
    {
        if(isset($this->connUidMap[$fd]))
        {
            return $this->connUidMap[$fd];
        }
        return 0;
    }
    
    /**
     * BusinessWorker通知本Gateway进程$uid用户合法，绑定到$socket_id
     * 后面这个socketid再有消息传来，会自动带上uid传递给BusinessWorker
     * @param int $socket_id
     * @param int $uid
     */
    protected function connectSuccess($socket_id, $uid)
    {
        $binded_uid = $this->getUidByFd($socket_id);
        if($binded_uid)
        {
            $this->notice('notify connection success fail ' . $socket_id . ' already binded ');
            return;
        }
        $this->uidConnMap[$uid] = $socket_id;
        $this->connUidMap[$socket_id] = $uid;
    }
    
    /**
     * 向某个socketId的连接发送消息
     * @param int $socket_id
     * @param string $bin_data
     */
    public function sendToSocketId($socket_id, $bin_data)
    {
        if(!isset($this->connections[$socket_id]))
        {
            return false;
        }
        $this->currentDealFd = $socket_id;
        return $this->sendToClient($bin_data);
    }

    /**
     * 用户客户端主动关闭连接触发
     * @see Man\Core.SocketWorker::closeClient()
     */
    protected function closeClient($fd)
    {
        if($uid = $this->getUidByFd($fd))
        {
            $this->sendToWorker(GatewayProtocol::CMD_ON_CLOSE, $fd);
            unset($this->uidConnMap[$uid], $this->connUidMap[$fd]);
        }
        parent::closeClient($fd);
    }
    
    /**
     * 内部通讯socket在BusinessWorker主动关闭连接时触发
     * @param int $fd
     */
    protected function closeInnerClient($fd)
    {
        unset($this->workerConnections[$fd]);
        parent::closeClient($fd);
    }
    
    /**
     * 随机抽取一个与BusinessWorker的长连接，将数据发给一个BusinessWorker
     * @param int $cmd
     * @param int $socket_id
     * @param string $body
     */
    protected function sendToWorker($cmd, $socket_id, $body = '')
    {
        $address= $this->getRemoteAddress($socket_id);
        list($client_ip, $client_port) = explode(':', $address, 2);
        $pack = new GatewayProtocol();
        $pack->header['cmd'] = $cmd;
        $pack->header['local_ip'] = $this->lanIp;
        $pack->header['local_port'] = $this->lanPort;
        $pack->header['socket_id'] = $socket_id;
        $pack->header['client_ip'] = $client_ip;
        $pack->header['client_port'] = $client_ip;
        $pack->header['uid'] = $this->getUidByFd($socket_id);
        $pack->body = $body;
        return $this->sendBufferToWorker($pack->getBuffer());
    }
    
    /**
     * 随机抽取一个与BusinessWorker的长连接，将数据发给一个BusinessWorker
     * @param string $bin_data
     */
    protected function sendBufferToWorker($bin_data)
    {
        $this->currentDealFd = array_rand($this->workerConnections);
        return $this->sendToClient($bin_data);
    }
    
    /**
     * 打印日志
     * @see Man\Core.AbstractWorker::notice()
     */
    protected function notice($str, $display=true)
    {
        $str = 'Worker['.get_class($this).']:'."$str ip:".$this->getRemoteIp();
        Man\Core\Lib\Log::add($str);
        if($display && Man\Core\Lib\Config::get('workerman.debug') == 1)
        {
            echo $str."\n";
        }
    }
    
    /**
     * 进程停止时，清除一些数据
     * @see Man\Core.SocketWorker::onStop()
     */
    public function onStop()
    {
        $this->unregisterAddress($this->lanIp.':'.$this->lanPort);
        foreach($this->connUidMap as $uid)
        {
            Store::delete($uid);
        }
    }
    
    /**
     * 向认证的用户发送心跳数据
     */
    public function ping()
    {
        $this->broadCast($this->pingData);
    }
}
