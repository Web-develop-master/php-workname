<?php

namespace Workerman\Events;

use RuntimeException;
use Swow\Coroutine;
use Swow\Signal;
use Swow\SignalException;
use Workerman\Worker;
use function getmypid;
use function max;
use function msleep;
use function stream_poll_one;
use function Swow\Sync\waitAll;
use const STREAM_POLLHUP;
use const STREAM_POLLIN;
use const STREAM_POLLNONE;
use const STREAM_POLLOUT;

class Swow implements EventInterface
{
    /**
     * All listeners for read timer
     * @var array
     */
    protected $eventTimer = [];

    /**
     * All listeners for read event.
     * @var array<Coroutine>
     */
    protected $readEvents = [];

    /**
     * All listeners for write event.
     * @var array<Coroutine>
     */
    protected $writeEvents = [];

    /**
     * All listeners for signal.
     * @var array<Coroutine>
     */
    protected $signalListener = [];

    /**
     * Get timer count.
     *
     * @return integer
     */
    public function getTimerCount()
    {
        return \count($this->eventTimer);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $t = (int) ($delay * 1000);
        $t = max($t, 1);
        $coroutine = Coroutine::run(function () use ($t, $func, $args): void {
            msleep($t);
            unset($this->eventTimer[Coroutine::getCurrent()->getId()]);
            try {
                $func(...(array) $args);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        });
        $timerId = $coroutine->getId();
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, $func, $args)
    {
        $t = (int) ($interval * 1000);
        $t = max($t, 1);
        $coroutine = Coroutine::run(static function () use ($t, $func, $args): void {
            while (true) {
                msleep($t);
                try {
                    $func(...(array) $args);
                } catch (\Throwable $e) {
                    Worker::stopAll(250, $e);
                }
            }
        });
        $timerId = $coroutine->getId();
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timerId)
    {
        if (isset($this->eventTimer[$timerId])) {
            try {
                (Coroutine::getAll()[$timerId])->kill();
                return true;
            } finally {
                unset($this->eventTimer[$timerId]);
            }
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->eventTimer as $timerId) {
            $this->deleteTimer($timerId);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $fd = (int) $stream;
        if (isset($this->readEvents[$fd])) {
            $this->offReadable($stream);
        }
        Coroutine::run(function () use ($stream, $func, $fd): void {
            try {
                $this->readEvents[$fd] = Coroutine::getCurrent();
                while (true) {
                    $rEvent = stream_poll_one($stream, STREAM_POLLIN | STREAM_POLLHUP);
                    if (!isset($this->readEvents[$fd]) || $this->readEvents[$fd] !== Coroutine::getCurrent()) {
                        break;
                    }
                    if ($rEvent !== STREAM_POLLNONE) {
                        $func($stream);
                    }
                    if ($rEvent !== STREAM_POLLIN) {
                        $this->offReadable($stream);
                        break;
                    }
                }
            } catch (RuntimeException) {
                $this->offReadable($stream);
            }
        });
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        // 在当前协程执行 $coroutine->kill() 会导致不可预知问题，所以没有使用$coroutine->kill()
        unset($this->readEvents[(int) $stream]);
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $fd = (int) $stream;
        if (isset($this->writeEvents[$fd])) {
            $this->offWritable($stream);
        }
        Coroutine::run(function () use ($stream, $func, $fd): void {
            try {
                $this->writeEvents[$fd] = Coroutine::getCurrent();
                while (true) {
                    $rEvent = stream_poll_one($stream, STREAM_POLLOUT | STREAM_POLLHUP);
                    if (!isset($this->writeEvents[$fd]) || $this->writeEvents[$fd] !== Coroutine::getCurrent()) {
                        break;
                    }
                    if ($rEvent !== STREAM_POLLNONE) {
                        $func($stream);
                    }
                    if ($rEvent !== STREAM_POLLOUT) {
                        $this->offWritable($stream);
                        break;
                    }
                }
            } catch (RuntimeException) {
                $this->offWritable($stream);
            }
        });
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        unset($this->writeEvents[(int) $stream]);
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        Coroutine::run(function () use ($signal, $func): void {
            $this->signalListener[$signal] = Coroutine::getCurrent();
            while (1) {
                try {
                    Signal::wait($signal);
                    if (!isset($this->signalListener[$signal]) ||
                        $this->signalListener[$signal] !== Coroutine::getCurrent()) {
                        break;
                    }
                    $func($signal);
                } catch (SignalException) {}
            }
        });
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        if (!isset($this->signalListener[$signal])) {
            return false;
        }
        unset($this->signalListener[$signal]);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        waitAll();
    }

    /**
     * Destroy loop.
     *
     * @return void
     */
    public function stop()
    {
        Coroutine::getMain()->kill();
    }

    public function destroy()
    {
        $this->stop();
    }

    public function clearAllTimer()
    {
        $this->deleteAllTimer();
    }

    public function loop()
    {
        waitAll();
    }
}