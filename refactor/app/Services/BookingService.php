<?php

namespace DTApi\Service;

use DTApi\Helpers\TeHelper;
use DTApi\Repository\BookingRepository;
use DTApi\Repository\JobRepository;

class BookingService
{
    protected $bookingRepository;
    protected $jobRepository;

    function __construct(BookingRepository $bookingRepository, JobRepository $jobRepository)
    {
        $this->bookingRepository = $bookingRepository;
        $this->jobRepository = $jobRepository;
    }

    public function getUsersJobs($user_id)
    {
        $cuser = $this->bookingRepository->find($user_id);
        $usertype = '';
        $emergencyJobs = array();
        $noramlJobs = array();
        if ($cuser && $cuser->is('customer')) {
            $jobs = $this->jobRepository->getJobByUser($cuser);
            $usertype = 'customer';
        } elseif ($cuser && $cuser->is('translator')) {
            $jobs = Job::getTranslatorJobs($cuser->id, 'new');
            $jobs = $jobs->pluck('jobs')->all();
            $usertype = 'translator';
        }
        if ($jobs) {
            foreach ($jobs as $jobitem) {
                if ($jobitem->immediate == 'yes') {
                    $emergencyJobs[] = $jobitem;
                } else {
                    $noramlJobs[] = $jobitem;
                }
            }
            $noramlJobs = collect($noramlJobs)->each(function ($item, $key) use ($user_id) {
                $item['usercheck'] = Job::checkParticularJob($user_id, $item);
            })->sortBy('due')->all();
        }

        return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'cuser' => $cuser, 'usertype' => $usertype];
    }

    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page');
        if (isset($page)) {
            $pagenum = $page;
        } else {
            $pagenum = "1";
        }
        $cuser = $this->bookingRepository->find($user_id);
        $usertype = '';
        $emergencyJobs = array();
        $noramlJobs = array();
        if ($cuser && $cuser->is('customer')) {
            $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])->orderBy('due', 'desc')->paginate(15);
            $usertype = 'customer';
            return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => [], 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => 0, 'pagenum' => 0];
        } elseif ($cuser && $cuser->is('translator')) {
            $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
            $totaljobs = $jobs_ids->total();
            $numpages = ceil($totaljobs / 15);

            $usertype = 'translator';

            $jobs = $jobs_ids;
            $noramlJobs = $jobs_ids;
            return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => $numpages, 'pagenum' => $pagenum];
        }
    }

    public function store($user, $data)
    {
        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;
        if ($user->user_type == env('CUSTOMER_ROLE_ID')) {
            $cuser = $user;

            if (!isset($data['from_language_id'])) {
                return $this->customReponse('fail', 'Du måste fylla in alla fält', 'from_language_id');
            }
            if ($data['immediate'] == 'no') {
                if (isset($data['due_date']) && $data['due_date'] == '') {
                    return $this->customReponse('fail', 'Du måste fylla in alla fält', 'due_date');
                }
                if (isset($data['due_time']) && $data['due_time'] == '') {
                    return $this->customReponse('fail', 'Du måste fylla in alla fält', 'due_time');
                }
                if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                    return $this->customReponse('fail', 'Du måste fylla in alla fält', 'customer_phone_type');
                }
                if (isset($data['duration']) && $data['duration'] == '') {
                    return $this->customReponse('fail', 'Du måste fylla in alla fält', 'duration');
                }
            }

            if (isset($data['customer_phone_type'])) {
                $data['customer_phone_type'] = 'yes';
            } else {
                $data['customer_phone_type'] = 'no';
            }

            if (isset($data['customer_physical_type'])) {
                $data['customer_physical_type'] = 'yes';
                $response['customer_physical_type'] = 'yes';
            } else {
                $data['customer_physical_type'] = 'no';
                $response['customer_physical_type'] = 'no';
            }

            if ($data['immediate'] == 'yes') {
                $due_carbon = Carbon::now()->addMinute($immediatetime);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';

            } else {
                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type'] = 'regular';
                $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                if ($due_carbon->isPast()) {
                    $response['status'] = 'fail';
                    $response['message'] = "Can't create booking in past";
                    return $response;
                }
            }
            if (in_array('male', $data['job_for']) || in_array('female', $data['job_for'])) {
                $data['gender'] = $data['job_for'];
            }

            if (in_array('normal', $data['job_for']) ||
                in_array('certified', $data['job_for']) ||
                in_array('certified_in_law', $data['job_for']) ||
                in_array('certified_in_helth', $data['job_for'])
            ) {
                $data['certified'] = $data['job_for'];
            }
            $certifiedArr = ['certified' => 'both', 'certified_in_law' => 'n_law', 'certified_in_helth' => 'n_health'];
            if ((in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) ||
                (in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for'])) ||
                in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = $certifiedArr[$data['job_for']];
            }
            $consumer_type_arr = ['rwsconsumer' => 'rws', 'ngo' => 'unpaid', 'paid' => 'paid'];
            if ($consumer_type == 'rwsconsumer' || $consumer_type == 'ngo' || $consumer_type == 'paid') {
                $data['job_type'] = $consumer_type_arr[$consumer_type];
            }
            $data['b_created_at'] = date('Y-m-d H:i:s');
            if (isset($due))
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
            $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';
            $job = $this->bookingRepository->store($user, $data);
            $response['status'] = 'success';
            $response['id'] = $job->id;
        } else {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";
        }
    }
}