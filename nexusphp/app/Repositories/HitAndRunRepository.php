<?php
namespace App\Repositories;

use App\Models\HitAndRun;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserBanLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class HitAndRunRepository extends BaseRepository
{

    public function cronjobUpdateStatus($uid = null, $torrentId = null)
    {
        $size = 1000;
        $page = 1;
        $setting = Setting::get('hr');
        if (empty($setting['mode'])) {
            do_log("H&R not set.");
            return false;
        }
        if ($setting['mode'] == HitAndRun::MODE_DISABLED) {
            do_log("H&R mode is disabled.");
            return false;
        }
        if (empty($setting['inspect_time'])) {
            do_log("H&R inspect_time is not set.");
            return false;
        }
        $query = HitAndRun::query()
            ->where('status', HitAndRun::STATUS_INSPECTING)
            ->where('created_at', '<', Carbon::now()->subHours($setting['inspect_time']))
            ->with([
                'torrent' => function ($query) {$query->select(['id', 'size', 'name']);},
                'snatch',
                'user' => function ($query) {$query->select(['id', 'username', 'lang']);},
                'user.language',
            ]);
        if (!is_null($uid)) {
            $query->where('uid', $uid);
        }
        if (!is_null($torrentId)) {
            $query->where('torrent_id', $torrentId);
        }
        $successCounts = 0;
        while (true) {
            $logPrefix = "page: $page, size: $size";
            $rows = $query->forPage($page, $size)->get();
            do_log("$logPrefix, counts: " . $rows->count());
            if ($rows->isEmpty()) {
                do_log("$logPrefix, no more data..." . last_query());
                break;
            }
            foreach ($rows as $row) {
                $logPrefix = "[HANDLING] " . $row->toJson();
                do_log($logPrefix);
                if (!$row->user) {
                    do_log("$logPrefix, user not exists, skip!", 'error');
                    continue;
                }
                if (!$row->snatch) {
                    do_log("$logPrefix, snatch not exists, skip!", 'error');
                    continue;
                }
                if (!$row->torrent) {
                    do_log("$logPrefix, torrent not exists, skip!", 'error');
                    continue;
                }

                //check seed time
                $targetSeedTime = $row->snatch->seedtime;
                $requireSeedTime = bcmul($setting['seed_time_minimum'], 3600);
                do_log("targetSeedTime: $targetSeedTime, requireSeedTime: $requireSeedTime");
                if ($targetSeedTime >= $requireSeedTime) {
                    $result = $this->reachedBySeedTime($row);
                    if ($result) {
                        $successCounts++;
                    }
                    continue;
                }

                //check share ratio
                $targetShareRatio = bcdiv($row->snatch->uploaded, $row->torrent->size, 4);
                $requireShareRatio = $setting['ignore_when_ratio_reach'];
                do_log("targetShareRatio: $targetShareRatio, requireShareRatio: $requireShareRatio");
                if ($targetShareRatio >= $requireShareRatio) {
                    $result = $this->reachedByShareRatio($row);
                    if ($result) {
                        $successCounts++;
                    }
                    continue;
                }

                //unreached
                $result = $this->unreached($row);
                if ($result) {
                    $successCounts++;
                }
            }
            $page++;
        }
        do_log("[CRONJOB_UPDATE_HR_DONE]");
        return $successCounts;
    }

    private function geReachedMessage(HitAndRun $hitAndRun): array
    {
        return [
            'receiver' => $hitAndRun->uid,
            'added' => Carbon::now()->toDateTimeString(),
            'subject' => nexus_trans('hr.reached_message_subject', ['hit_and_run_id' => $hitAndRun->id], $hitAndRun->user->locale),
            'msg' => nexus_trans('hr.reached_message_content', [
                'completed_at' => $hitAndRun->snatch->completedat->toDateTimeString(),
                'torrent_id' => $hitAndRun->torrent_id,
                'torrent_name' => $hitAndRun->torrent->name,
            ], $hitAndRun->user->locale),
        ];
    }

    private function reachedByShareRatio(HitAndRun $hitAndRun): bool
    {
        do_log(__METHOD__);
        $comment = nexus_trans('hr.reached_by_share_ratio_comment', [
            'now' => Carbon::now()->toDateTimeString(),
            'seed_time_minimum' => Setting::get('hr.seed_time_minimum'),
            'seed_time' => bcdiv($hitAndRun->snatch->seedtime, 3600, 1),
            'share_ratio' => get_hr_ratio($hitAndRun->snatch->uploaded, $hitAndRun->snatch->downloaded),
            'ignore_when_ratio_reach' => Setting::get('hr.ignore_when_ratio_reach'),
        ], $hitAndRun->user->locale);
        $update = [
            'status' => HitAndRun::STATUS_REACHED,
            'comment' => $comment
        ];
        $affectedRows = DB::table($hitAndRun->getTable())
            ->where('id', $hitAndRun->id)
            ->where('status', HitAndRun::STATUS_INSPECTING)
            ->update($update);
        do_log("[H&R_REACHED_BY_SHARE_RATIO], " . last_query() . ", affectedRows: $affectedRows");
        if ($affectedRows != 1) {
            do_log($hitAndRun->toJson() . ", [H&R_REACHED_BY_SHARE_RATIO], affectedRows != 1, skip!", 'notice');
            return false;
        }
        $message = $this->geReachedMessage($hitAndRun);
        Message::query()->insert($message);
        return true;
    }

    private function reachedBySeedTime(HitAndRun $hitAndRun): bool
    {
        do_log(__METHOD__);
        $comment = nexus_trans('hr.reached_by_seed_time_comment', [
            'now' => Carbon::now()->toDateTimeString(),
            'seed_time' => bcdiv($hitAndRun->snatch->seedtime, 3600, 1),
            'seed_time_minimum' => Setting::get('hr.seed_time_minimum')
        ], $hitAndRun->user->locale);
        $update = [
            'status' => HitAndRun::STATUS_REACHED,
            'comment' => $comment
        ];
        $affectedRows = DB::table($hitAndRun->getTable())
            ->where('id', $hitAndRun->id)
            ->where('status', HitAndRun::STATUS_INSPECTING)
            ->update($update);
        do_log("[H&R_REACHED_BY_SEED_TIME], " . last_query() . ", affectedRows: $affectedRows");
        if ($affectedRows != 1) {
            do_log($hitAndRun->toJson() . ", [H&R_REACHED_BY_SEED_TIME], affectedRows != 1, skip!", 'notice');
            return false;
        }
        $message = $this->geReachedMessage($hitAndRun);
        Message::query()->insert($message);
        return true;
    }

    private function unreached(HitAndRun $hitAndRun): bool
    {
        do_log(__METHOD__);

        $comment = nexus_trans('hr.unreached_comment', [
            'now' => Carbon::now()->toDateTimeString(),
            'seed_time' => bcdiv($hitAndRun->snatch->seedtime, 3600, 1),
            'seed_time_minimum' => Setting::get('hr.seed_time_minimum'),
            'share_ratio' => get_hr_ratio($hitAndRun->snatch->uploaded, $hitAndRun->snatch->downloaded),
            'torrent_size' => mksize($hitAndRun->torrent->size),
            'ignore_when_ratio_reach' => Setting::get('hr.ignore_when_ratio_reach')
        ], $hitAndRun->user->locale);
        $update = [
            'status' => HitAndRun::STATUS_UNREACHED,
            'comment' => $comment
        ];
        $affectedRows = DB::table($hitAndRun->getTable())
            ->where('id', $hitAndRun->id)
            ->where('status', HitAndRun::STATUS_INSPECTING)
            ->update($update);
        do_log("[H&R_UNREACHED], " . last_query() . ", affectedRows: $affectedRows");
        if ($affectedRows != 1) {
            do_log($hitAndRun->toJson() . ", [H&R_UNREACHED], affectedRows != 1, skip!", 'notice');
            return false;
        }
        $message = [
            'receiver' => $hitAndRun->uid,
            'added' => Carbon::now()->toDateTimeString(),
            'subject' => nexus_trans('hr.unreached_message_subject', ['hit_and_run_id' => $hitAndRun->id], $hitAndRun->user->locale),
            'msg' => nexus_trans('hr.unreached_message_content', [
                'completed_at' => $hitAndRun->snatch->completedat->toDateTimeString(),
                'torrent_id' => $hitAndRun->torrent_id,
                'torrent_name' => $hitAndRun->torrent->name,
            ], $hitAndRun->user->locale),
        ];
        Message::query()->insert($message);

        //disable user
        /** @var User $user */
        $user = $hitAndRun->user;
        $counts = $user->hitAndRuns()->where('status', HitAndRun::STATUS_UNREACHED)->count();
        $disableCounts = Setting::get('hr.ban_user_when_counts_reach');
        do_log("user: {$user->id}, H&R counts: $counts, disableCounts: $disableCounts", 'notice');
        if ($counts >= $disableCounts) {
            do_log("[DISABLE_USER_DUE_TO_H&R_UNREACHED]", 'notice');
            $comment = nexus_trans('hr.unreached_disable_comment', [], $user->locale);
            $user->updateWithModComment(['enabled' => User::ENABLED_NO], $comment);
            $message = [
                'receiver' => $hitAndRun->uid,
                'added' => Carbon::now()->toDateTimeString(),
                'subject' => $comment,
                'msg' => nexus_trans('hr.unreached_disable_message_content', [
                    'ban_user_when_counts_reach' => Setting::get('hr.ban_user_when_counts_reach'),
                ], $hitAndRun->user->locale),
            ];
            Message::query()->insert($message);
            $userBanLog = [
                'uid' => $user->id,
                'username' => $user->username,
                'reason' => $comment
            ];
            UserBanLog::query()->insert($userBanLog);
        }

        return true;
    }
}
