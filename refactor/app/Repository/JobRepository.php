<?php

namespace DTApi\Repository;

class JobRepository extends BaseRepository {

    public function getJobByUser($user)
    {
        $data['jobs'] = Job::where('user_id', '=', $user->id)->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')->whereIn('status', ['pending', 'assigned', 'started'])
            ->orderBy('due', 'asc')->get();
        return $data;
    }

    public function getJobHistoryByUser($user)
    {
        $data['jobs'] = Job::where('user_id', '=', $user->id)->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
            ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])->orderBy('due', 'desc')->paginate(15);
        return $data;
    }
}