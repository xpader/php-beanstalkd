<?php
/**
 * Created by PhpStorm.
 * User: pader
 * Date: 2019/7/21
 * Time: 13:31
 */

namespace xpader\beanstalkd;
use Workerman\Lib\Timer;

/**
 * Class Tube
 * @package xpader\beanstalkd
 * 
 * @property \SplPriorityQueue $queue
 * @method void addWatch(Connection $connection)
 * @method void removeWatch(Connection $connection)
 * @method void addUse(Connection $connection)
 * @method void removeUse(Connection $connection)
 * @method void addReserve(Connection $connection)
 * @method void removeReserve(Connection $connection)
 */
class Tube
{

	/**
	 * @var string
	 */
	public $name;

	/**
	 * @var Server
	 */
	public $server;

	/**
	 * @var \SplPriorityQueue
	 */
	protected $queueReady;

	protected $queueBuried = [];
	protected $queueDelayed = [];
	protected $queueReserved = [];

	/**
	 * @var Connection[]
	 */
	public $watchs = [];

	/**
	 * @var Connection[]
	 */
	public $uses = [];

	/**
	 * @var Connection[]
	 */
	public $reserves = [];

	private $totalJobs = 0;

	public function __construct($name, $server) {
		$this->name = $name;
		$this->server = $server;
	}

	public function __get($name) {
		if ($name == 'queue') {
			if ($this->queueReady === null) {
				$this->queueReady = new Queue();
				$this->queueReady->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
			}

			return $this->queueReady;
		} else {
			throw new \RuntimeException("Getting undefined property '$name'.");
		}
	}

	public function __call($name, $arguments) {
		if (substr($name, 0, 3) == 'add') {
			$cmd = 'add';
			$target = strtolower(substr($name, 3));
		} elseif (substr($name, 0, 6) == 'remove') {
			$cmd = 'remove';
			$target = strtolower(substr($name, 6));
		} else {
			throw new \BadMethodCallException("Call to undefined method '$name'.");
		}

		if (!in_array($target, ['watch', 'use', 'reserve'])) {
			throw new \BadMethodCallException("Call to undefined method '$name'.");
		}

		$target .= 's';

		if ($cmd == 'add') {
			$this->addClient($target, $arguments[0]);
		} else {
			$this->removeClient($target, $arguments[0]);
		}

		if ($name == 'addReserve') {
			$this->dispatch();
		}
	}

	public function put($id, $priority, $status)
	{
		if ($status == Job::STATUS_READY) {
			$this->queue->insert($id, $priority);
			$this->dispatch();
		} else {

		}

		++$this->totalJobs;
	}

	/**
	 * @param int|Job $id
	 * @param int $delay
	 */
	public function release($id, $delay=0)
	{
		if ($id instanceof Job) {
			$job = $id;
		} else {
			$job = $this->server->getJob($id);
			if (!$job) {
				return;
			}
		}

		if ($job->status != Job::STATUS_RESERVED) {
			return;
		}

		$this->queue->insert($id, $job->pri);
		if (isset($this->queueReserved[$id])) {
			unset($this->queueReserved[$id]);
		}
		$job->status = Job::STATUS_READY;
		$this->dispatch();
	}

	protected function dispatch()
	{
		if ($this->queue->isEmpty() || count($this->reserves) == 0) {
			return;
		}

		while ($this->queue->valid()) {
			$id = $this->queue->current();
			$job = $this->server->getJob($id);

			//The job maybe deleted or other status.
			if ($job === null || $job->status != Job::STATUS_READY) {
				$this->queue->next();
				continue;
			}

			while ($connection = array_shift($this->reserves)) {
				if ($connection->reserving) {
					$connection->send(sprintf('RESERVED %d %d %s', $id, strlen($job->value), $job->value));
					$connection->reserving = false;
					$job->status = Job::STATUS_RESERVED;
					$this->queueReserved[$job->id] = $job->id;
					Timer::add($job->ttr, [$this, 'release'], $job->id, false);
					$this->queue->next();
					break;
				}
			}

			if (count($this->reserves) == 0) {
				break;
			}
		}
	}

	/**
	 * @param Job $job
	 */
	public function bury($job)
	{
		if (isset($this->queueReserved[$job->id])) {
			unset($this->queueReserved[$job->id]);
		}
		$job->status = Job::STATUS_BURIED;
		$this->queueBuried[$job->id] = $job->id;
	}

	public function stats()
	{
		return [
			'name' => $this->name,
			'current-jobs-urgent' => 0,
			'current-jobs-ready' => $this->queue->count(),
			'current-jobs-reserved' => 0,
			'current-jobs-delayed' => 0,
			'current-jobs-buried' => 0,
			'total-jobs' => $this->totalJobs,
			'current-using' => count($this->uses),
			'current-watching' => count($this->watchs),
			'current-waiting' => count($this->reserves),
			'cmd-delete' => 0,
			'cmd-pause-tube' => 0,
			'pause' => 0,
			'pause-time-left' => 0
		];
	}

	/**
	 * @param string $to
	 * @param Connection $connection
	 */
	protected function addClient($to, $connection)
	{
		if (!in_array($connection, $this->$to, true)) {
			$this->{$to}[] = $connection;
		}
	}

	/**
	 * @param string $from
	 * @param Connection $connection
	 */
	protected function removeClient($from, $connection)
	{
		$key = array_search($connection, $this->$from, true);
		if ($key !== false) {
			unset($this->{$from}[$key]);
		}
	}

	/**
	 * 检查当前队列是否为空
	 *
	 * 无队列数据，且无任何 watching, use, reserve 的客户端即代表为空
	 *
	 * @return bool
	 */
	public function isEmpty()
	{
		return $this->queue->isEmpty() && empty($this->watchs) && empty($this->uses) && empty($this->reserves);
	}

	public function __destruct() {
		$this->queueReady = $this->watchs = $this->reserves = $this->uses = null;
	}

}