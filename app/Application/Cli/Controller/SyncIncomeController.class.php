<?php
namespace Cli\Controller;
use Think\Controller;

class SyncIncomeController extends Controller {

	private $admin_user;
	private $rate;

	// */1 * * * * /usr/local/php/bin/php /var/www/app/index.php Cli/SyncIncome/index
	public function index() {
		$this->admin_user = M('admin_user');
		$this->rate = getConfig('rate');//回佣率
		$betLog = M('bet_log');
		$adminIncome = M('admin_income');
		$adminUser = M('admin_user');
		$adminWasteBook = M('admin_waste_book');
		$list = $betLog->where(['sync'=> 0])->field('id,user_id,issue,is_host,bet_balance,profit_balance,commission,add_time')->select();
		foreach ($list as $key => $value) {
			$win_balance = bcdiv($value['commission'], $this->rate, 2);
			if (C('PATTERN') == 1) {
				$win_balance = bcmul($win_balance, 2, 2);
			}
			$adminList = $this->getAdminList($value['user_id'], $value['commission']);
			foreach ($adminList as $k => $v) {
				$adminIncome->add([
					'admin_id' => $v['user_id'],
					'bet_id' => $value['id'],
					'user_id' => $value['user_id'],
					'issue' => $value['issue'],
					'is_host' => $value['is_host'],
					'bet_balance' => $value['bet_balance'],
					'profit_balance' => $value['profit_balance'],
					'win_balance' => $win_balance,
					'commission' => $v['commission'],
					'add_time' => time()
				]);
				$before_balance = $adminUser->where(['user_id'=>$v['user_id']])->getField('balance');
				$after_balance = bcadd($before_balance, $v['commission'], 2);
				$adminUser->where(['user_id'=>$v['user_id']])->save(['balance'=> $after_balance]);
				// 插入流水表
				$adminWasteBook->add([
					'user_id'=> $v['user_id'],
					'before_balance'=> $before_balance,
					'after_balance'=> $after_balance,
					'change_balance'=> $v['commission'],
					'type'=> 1,
					'add_time'=> $value['add_time'],
				]);
			}
			// 同步下注表状态
			$betLog->where(['id'=> $value['id']])->save(['sync'=> 1]);
		}
	}

	// 根据用户ID获取全部的代理ID和佣金率
	private function getAdminList($user_id, $all_commission) {
		// 找出user对应的所有的代理
		$invite_code = M('user')->where(['user_id'=> $user_id])->getField('invite_code');
		$adminInfo = $this->admin_user->where(['invite_code'=> $invite_code])->field('user_id,pid,rate')->find();
		$adminList = $this->getAdminRateList([$adminInfo], $adminInfo['pid']);
		// 分钱
		$tempSum = 0;
		$last_rate = 0;
		foreach ($adminList as $key => $value) {
			if ($key == count($adminList) - 1) {
				$commission = bcsub($all_commission, $tempSum, 2);
			} else {
				$commission = bcdiv(bcmul(bcdiv($all_commission, $this->rate, 2), $value['rate'], 2), 100, 2);
				$tempSum = bcadd($tempSum, $commission, 2);
			}
			$last_rate = $value['rate'];
			$adminList[$key]['commission'] = $commission;
		}
		return $adminList;
	}

	// 递归获取代理列表
	private function getAdminRateList($adminList, $pid) {
		$adminInfo = $this->admin_user->where(['user_id'=> $pid])->field('user_id,pid,rate')->find();
		if (!empty($adminInfo)) {
			$adminList[] = $adminInfo;
			$adminList = $this->getAdminRateList($adminList, $adminInfo['pid']);
		}
		return $adminList;
	}
}