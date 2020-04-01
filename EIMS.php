<?php
/**
 * Created by PhpStorm.
 * User: MINJE
 * Date: 2017. 6. 15.
 * Time: PM 4:39
 */

namespace App\Libraries;

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Controller;
use App\Models\_User;
use App\Models\AdjustContractDetail;
use App\Models\AdjustDefaultRate;
use App\Models\AdjustDrDetail;
use App\Models\AdjustEtcDetail;
use App\Models\AdjustEtcRate;
use App\Models\AdjustKeDevicePrice;
use App\Models\AdjustKeUserDetail;
use App\Models\AdjustKeUserRate;
use App\Models\AdjustUserDetail;
use App\Models\CnSystemGasAdjust;
use App\Models\CnSystemGasAdjustResource;
use App\Models\Code;
use App\Models\Contract;
use App\Models\ContractCapacity;
use App\Models\Device;
use App\Models\Job;
use App\Models\Reduce;
use App\Models\ReduceContract;
use App\Models\ReduceContractInterval;
use App\Models\ReduceInterval;
use App\Models\ReducePriceUnit;
use App\Models\ReqReduceDesc;
use App\Models\Wattage;
use App\Models\WattageDay;
use App\Models\WattageFive;
use App\Models\WattageFiveDay;
use App\Models\WattageFiveHM;
use App\Models\WattageHM;
use App\Models\Dr;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use ErrorException;
use Illuminate\Support\Facades\DB;

trait EIMS
{
    use CacheEIMS;

    private $RDC_RT_MAX = 150;

    public function getContractWattageByDay($contract_idx, $date)
    {
        $contract = Contract::with('Devices')->find($contract_idx);
        if ($contract->count() > 0) {
            $devices = $contract->Devices;
            if ($devices->count() > 0) {
                $hh_mm = WattageFiveHM::all();
                $wattage5 = WattageFive::where([
                    'ymd' => strval($date),
                    'kepco_code' => $contract->kepco_code,
                    'ref_contract_idx' => $contract->idx
                ])->get();

                $wattage = Wattage::where(['ymd' => strval($date), 'kepco_code' => $contract->kepco_code, 'ref_contract_idx' => $contract->idx])->get();
                $ret = [];
                foreach ($hh_mm as $hm) {
                    foreach ($devices as $device) {
                        $ret['w5'][$hm->hh . $hm->mm][$device->device] = 0;
                    }
                }
                foreach ($wattage5 as $w5) {
                    $ret['w5'][sprintf('%02d', $w5->h) . sprintf('%02d', $w5->m)][$w5->device] = round($w5->v, 2);
                }
                foreach ($wattage as $w) {
                    $ret['w'][$w->h . $w->m] = $w->v;
                }
                return $ret;
            } else {
                throw new ModelNotFoundException('해당 참여고객의 5분 검침기 장비가 없습니다.');
            }
        } else {
            throw new ModelNotFoundException('해당 참여고객이 없습니다.');
        }
    }

    /**
     *
     * Create by tk.kim 2017.06
     *
     * @param $reduce_idx
     * @param $contracts
     * @param int $type
     * @return array
     */
    public function getReduceContractReport($reduce_idx, $contracts, $type = 5)
    {
        $reduceContractsReport = [];
        $reduceTypes = config('eims.ReduceType');
        foreach ($contracts as $item) {
            $reduceContractReport = null; #Cache::get(config('cache.keys.참여고객별_십오분보고서') . "{$reduce_idx}-{$item}");
            $intervalCnt = 0;
            $intervalV = 0;
            $reduce = Reduce::select(['start_date', 'duration', 'reduce_type'])->where(['idx' => $reduce_idx])->first();
            $contract = Contract::select(['idx', 'company_id', 'name', 'capacity', 'kepco_code', 'ref_user_code'])->where(['idx' => $item])->first();
            if ($contract and $reduce) {
                $contractInfo = [];
                $contractInfo['name'] = $contract->name;
                $contractInfo['capacity'] = 0;
                $contractCapacity = ContractCapacity::select('capacity')->where(['ref_contract_idx' => $item, 'ym' => Carbon::createFromTimestamp($reduce->start_date)->format('Ym')])->first();
                if (empty($contractCapacity)) $contractCapacity = ContractCapacity::select('capacity')->where(['ref_contract_idx' => $item, 'ym' => Carbon::createFromTimestamp($reduce->start_date)->addMonth()->format('Ym')])->first();
                if ($contractCapacity) $contractInfo['capacity'] = $contractCapacity->capacity;
                $contractInfo['kepco_code'] = $contract->kepco_code;
                $contractInfo['start_date'] = Carbon::createFromTimestamp($reduce->start_date)->toDateTimeString();
                $contractInfo['duration'] = $reduce->duration;
                $contractInfo['reduce_typeTitle'] = findConfigValueName($reduceTypes, $reduce->reduce_type);
                $contractInfo['user_name'] = '-';
                $s_date = Carbon::createFromTimestamp($reduce->start_date)->format('Ymd');
                $reduceContractIntervals = ReduceContractInterval::select([
                    'start_h', 'start_m', 'end_h', 'end_m', 'reduce_order_qty', 'reduce_qty', 'finish', 'ref_contract_idx'
                ])->where(['ref_reduce_idx' => $reduce_idx, 'ref_contract_idx' => $contract->idx])->get();
                $intervalStatus = [];
                $intervalHours = [];
                foreach ($reduceContractIntervals as $reduceContractInterval) {
                    $intervalHours[] = $reduceContractInterval['start_h'];
                    $intervalStatus[] = $this->getReduceState($reduceContractInterval);
                    try {
                        # EIMS-1217
                        $contractInfo['user_name'] = (in_array($contract->company_id, config('eims.KE_ADJUST_COMPANYS'))) ? $reduceContractInterval['AdjustKeUserRate']['User']->name : $reduceContractInterval['AdjustDefaultRateUser']['User']->name;
                    } catch (Exception $e) {
                        $contractInfo['user_name'] = '-';
                    }
                }
                $contractInfo['interval_hours'] = $intervalHours;
                if ($type == 15) {
                    $contractIntervals = $this->getContractIntervalsFifteen($reduce_idx, $item, $contract->kepco_code, $s_date, $reduce->reduce_type);
                    $contractIntervalArray = [];
                    if ($contractIntervals) {
                        foreach ($contractIntervals as $key => $contractInterval) {
                            if ($contractInterval->q == '-') {
                                unset($contractIntervals[$key]);
                                continue;
                            }
                            if ($contractInterval->v == 0) $contractInterval->r = 0;
                            $contractInterval->user_name = $contractInfo['user_name'];
                            if ($contractInterval->finish == 1 and $contractInterval->v != 0) {
                                $intervalCnt++;
                                $intervalV += $contractInterval->v;
                            }
                            $contractIntervalArray[] = $contractInterval;
                        }
                    } else {
                        foreach ($reduceContractIntervals as $reduceContractInterval) {
                            $cnt = $this->getFifteenSubTotalCnt($reduceContractInterval);
                            $tmp = 1;
                            while (true) {
                                $contractIntervalArray[] = (object)[
                                    "reduce_qty" => 0,
                                    "h" => Carbon::parse("2018-01-01 {$reduceContractInterval->start_h}:{$reduceContractInterval->start_m}:00")->addMinutes($tmp * 15)->format('H'),
                                    "m" => Carbon::parse("2018-01-01 {$reduceContractInterval->start_h}:{$reduceContractInterval->start_m}:00")->addMinutes($tmp * 15)->format('i'),
                                    "finish" => 0,
                                    "reduce_order_qty" => 0,
                                    "v" => 0,
                                    "cbl" => 0,
                                    "u" => 0,
                                    "q" => 0,
                                    "r" => "0",
                                    "reduce_rate" => "0",
                                    "reduce_target_v" => "0",
                                    "start_h" => $reduceContractInterval->start_h,
                                    "start_m" => $reduceContractInterval->start_m,
                                    "end_h" => $reduceContractInterval->end_h,
                                    "end_m" => $reduceContractInterval->end_m,
                                    "user_name" => $contractInfo['user_name']
                                ];
                                if ($cnt == $tmp) break;
                                $tmp++;
                            }
                        }
                    }

                    $reduceContractReport = [
                        'contractInfo' => $contractInfo,
                        'contractIntervals' => $contractIntervalArray,
                        'interval_status' => $intervalStatus
                    ];
                } else {
                    $contractIntervals = $this->getContractIntervalsFive($reduce_idx, $item, $contract->kepco_code, $s_date, $reduce->reduce_type);
                    if (empty($contractIntervals)) $contractIntervals = [];
                    $item_cnt = 0;
                    if ($contractIntervals) {
                        foreach ($contractIntervals as $key => $contractInterval) {
                            $item_cnt++;
                            if ($contractInterval->u != 0) $contractInterval->u = round($contractInterval->u, 2);
                            $contractInterval->user_name = $contractInfo['user_name'];
                            if (is_null($contractInterval->cbl)) $contractInterval->q = null;
                            if ($contractInterval->finish == 1 and $contractInterval->v != 0) {
                                $intervalCnt++;
                                $intervalV += $contractInterval->v;
                            }

                            if ($contractInterval->interval_item_cnt == $item_cnt) {
                                $reduce_rate_data = [
                                    'reduce_qty' => $contractInterval->u,
                                    'reduce_order_qty' => $contractInterval->q
                                ];

                                $contractInterval->r = $this->getReducePercent($reduce_rate_data);
                                $item_cnt = 0;
                            }
                        }
                    } else {
                        foreach ($reduceContractIntervals as $reduceContractInterval) {
                            $cnt = $this->getFiveSubTotalCnt($reduceContractInterval) - 1;
                            $tmp = 0;
                            while (true) {
                                $contractIntervals[] = (object)[
                                    "reduce_qty" => 0,
                                    "hh" => Carbon::parse("2018-01-01 {$reduceContractInterval->start_h}:{$reduceContractInterval->start_m}:00")->addMinutes($tmp * 5)->format('H'),
                                    "mm" => Carbon::parse("2018-01-01 {$reduceContractInterval->start_h}:{$reduceContractInterval->start_m}:00")->addMinutes($tmp * 5)->format('i'),
                                    "finish" => 0,
                                    "reduce_order_qty" => 0,
                                    "v" => 0,
                                    "cbl" => 0,
                                    "u" => 0,
                                    "q" => 0,
                                    "r" => "0",
                                    "reduce_rate" => "0",
                                    "reduce_target_v" => "0",
                                    "start_h" => $reduceContractInterval->start_h,
                                    "start_m" => $reduceContractInterval->start_m,
                                    "end_h" => $reduceContractInterval->end_h,
                                    "end_m" => $reduceContractInterval->end_m,
                                    "user_name" => $contractInfo['user_name']
                                ];
                                if ($cnt == $tmp) break;
                                $tmp++;
                            }
                        }
                    }
                    $reduceContractReport = [
                        'contractInfo' => $contractInfo,
                        'contractIntervals' => $contractIntervals,
                        'interval_status' => $intervalStatus
                    ];
                }
                $reduceContractsReport[] = $reduceContractReport;
            }
        }

        return $reduceContractsReport;
    }

    public function getContractIntervalsFive($reduce_idx, $contract_idx, $kepco_code, $start_date, $reduce_type)
    {
        $cbl = $reduce_type == 1 ? "cbl_sr" : "cbl_dr";
        # v - 사용량, cbl - CBL, u - 감축량, q - 지시용량, r - 감축율, reduce_target_v - 목표감축량
        $sql = "SELECT ROUND(a.reduce_qty,2) AS reduce_qty, d.hh, d.mm, c.finish, c.reduce_order_qty,
                    ROUND(SUM(b.v),2) AS v,
                    ROUND(SUM(b.{$cbl}),3) AS cbl,
                    ROUND(SUM(b.{$cbl}) - SUM(b.v),2) AS u,
                    CASE WHEN c.ref_reduce_idx IS NULL THEN '0' ELSE ROUND(c.reduce_order_qty / (c.duration / 5),2) END AS q,
                    CASE WHEN c.ref_reduce_idx IS NULL THEN '0' ELSE ROUND(((SUM(b.{$cbl}) - SUM(b.v)) / (c.reduce_order_qty / (c.duration / 5))) * 100,2) END AS r,
                    CASE WHEN c.ref_reduce_idx IS NULL THEN '0' ELSE ROUND((a.reduce_qty / a.reduce_order_qty) * 100,2) END AS reduce_rate,
                    CASE WHEN c.ref_reduce_idx IS NULL THEN '0' ELSE ROUND(SUM(b.{$cbl}) - (c.reduce_order_qty / (c.duration / 5)), 2) END AS reduce_target_v,
                    a.start_h, a.start_m, a.end_h, a.end_m, ROUND(c.duration / 5) as interval_item_cnt
                FROM _reduce_interval a
                INNER JOIN _wattage5_hm d
                    ON CAST(CONCAT(d.hh, d.mm) AS UNSIGNED) >= CAST(CONCAT(a.start_h, a.start_m) AS UNSIGNED)
                    AND CAST(CONCAT(d.hh, d.mm) AS UNSIGNED) < CAST(CONCAT(a.end_h, a.end_m) AS UNSIGNED)
                LEFT OUTER JOIN _reduce_contract_interval c
                    ON a.ref_reduce_idx = c.ref_reduce_idx
                    AND c.ref_contract_idx = ?
                    AND a.start_h = c.start_h
                    AND a.start_m = c.start_m
                    AND a.end_h = c.end_h
                    AND a.end_m = c.end_m
                RIGHT JOIN _wattage5 b
                    ON b.kepco_code = ? and b.ref_contract_idx = c.ref_contract_idx
                    AND b.ymd = ?
                    AND b.h = d.h
                    AND b.m = d.m
                WHERE c.ref_reduce_idx = ?
                GROUP BY a.reduce_qty, d.hh, d.mm, c.finish, c.ref_reduce_idx, c.reduce_order_qty, a.reduce_order_qty, a.start_h, a.start_m, a.end_h, a.end_m, c.duration
                ORDER BY d.hh, d.mm ASC;";
        return DB::select($sql, [$contract_idx, $kepco_code, $start_date, $reduce_idx]);
    }

    public function getContractIntervalsFiveByNo($reduce_idx, $contract_idx, $kepco_code, $start_date, $reduce_type, $no)
    {
        $cbl = $reduce_type == 1 ? "cbl_sr" : "cbl_dr";
        # v - 사용량, cbl - CBL, u - 감축량, q - 지시용량, r - 감축율, reduce_target_v - 목표감축량
        $sql = "SELECT ROUND(a.reduce_qty,2) AS reduce_qty, d.hh, d.mm, c.finish, c.reduce_order_qty,
                    ROUND(SUM(b.v),2) AS v,
                    ROUND(SUM(b.{$cbl}),3) AS cbl,
                    ROUND(SUM(b.{$cbl}) - SUM(b.v),2) AS u,
                    CASE WHEN c.ref_reduce_idx IS NULL THEN '0' ELSE ROUND((c.reduce_order_qty / (c.duration / 5)),2) END AS q,
                    CASE WHEN c.ref_reduce_idx IS NULL THEN '0' ELSE ROUND((SUM(b.{$cbl}) - SUM(b.v)) / (c.reduce_order_qty / (c.duration / 5)) * 100,2) END AS r,
                    CASE WHEN c.ref_reduce_idx IS NULL THEN '0' ELSE ROUND((a.reduce_qty / a.reduce_order_qty) * 100,2) END AS reduce_rate,
                    CASE WHEN c.ref_reduce_idx IS NULL THEN '0' ELSE ROUND(SUM(b.{$cbl}) - (c.reduce_order_qty / (c.duration / 5)), 2) END AS reduce_target_v,
                    a.end_h
                FROM _reduce_interval a
                INNER JOIN _wattage5_hm d
                    ON CAST(CONCAT(d.hh, d.mm) AS UNSIGNED) >= CAST(CONCAT(a.start_h, a.start_m) AS UNSIGNED)
                    AND CAST(CONCAT(d.hh, d.mm) AS UNSIGNED) < CAST(CONCAT(a.end_h, a.end_m) AS UNSIGNED)
                LEFT OUTER JOIN _reduce_contract_interval c
                    ON a.ref_reduce_idx = c.ref_reduce_idx
                    AND c.ref_contract_idx = ?
                    AND a.start_h = c.start_h
                    AND a.start_m = c.start_m
                    AND a.end_h = c.end_h
                    AND a.end_m = c.end_m
                RIGHT JOIN _wattage5 b
                    ON b.kepco_code = ? and b.ref_contract_idx = c.ref_contract_idx
                    AND b.ref_contract_idx = c.ref_contract_idx
                    AND b.ymd = ?
                    AND b.h = d.h
                    AND b.m = d.m
                WHERE c.ref_reduce_idx = ? AND c.no = ?
                GROUP BY a.reduce_qty, d.hh, d.mm, c.finish, c.ref_reduce_idx, c.reduce_order_qty, a.reduce_order_qty, c.duration
                ORDER BY d.hh, d.mm ASC;";
        return DB::select($sql, [$contract_idx, $kepco_code, $start_date, $reduce_idx, $no]);
    }

    /**
     *
     * Create by tk.kim 2017.07
     *
     * @param $reduce_idx integer
     * @param $contract_idx array
     * @param $kepco_code array
     * @param $ymd string
     * @param $reduce_type integer
     * @param $no array
     * @return mixed
     */
    public function getReduceIntervalsFive($reduce_idx, $contract_idx, $kepco_code, $ymd, $reduce_type, $no)
    {
        $cbl = $reduce_type == 1 ? "cbl_sr" : "cbl_dr";
        # v - 사용량, cbl - CBL, u - 감축량, q - 지시용량, r - 감축율, reduce_target_v - 목표감축량
        $sql = "SELECT
            d.hh,
            d.mm,
            ROUND(SUM(b.v), 2)                              AS v,
            ROUND(SUM(b.{$cbl}), 3)                         AS cbl,
            a.finish,
          CASE WHEN c.ref_reduce_idx IS NULL
            THEN '0'
          ELSE ROUND(SUM(c.reduce_order_qty) / (c.duration / 5), 2) END AS reduce_order_qty,
            a.end_h,
            a.duration
        FROM _reduce_interval a
        INNER JOIN _wattage5_hm d
            ON CAST(CONCAT(d.hh, d.mm) AS UNSIGNED) >= CAST(CONCAT(a.start_h, a.start_m) AS UNSIGNED)
            AND CAST(CONCAT(d.hh, d.mm) AS UNSIGNED) < CAST(CONCAT(a.end_h, a.end_m) AS UNSIGNED)
        LEFT OUTER JOIN _reduce_contract_interval c
            ON a.ref_reduce_idx = c.ref_reduce_idx
            AND a.start_h = c.start_h
            AND a.start_m = c.start_m
            AND a.end_h = c.end_h
            AND a.end_m = c.end_m
        LEFT JOIN `_contract` e ON c.ref_contract_idx = e.idx
        RIGHT JOIN _wattage5 b
            ON b.kepco_code in ({$kepco_code})
            AND b.ref_contract_idx = c.ref_contract_idx
            AND b.ymd = {$ymd}
            AND b.h = d.h
            AND b.m = d.m
            AND b.kepco_code = e.kepco_code
        WHERE c.ref_reduce_idx = {$reduce_idx} AND c.no in ({$no}) and c.ref_contract_idx in ({$contract_idx})
        GROUP BY d.hh, d.mm, c.ref_reduce_idx, a.finish, c.duration
        ORDER BY d.hh, d.mm ASC;";

        return DB::select($sql);
    }

    public function getContractIntervalsFifteen($reduce_idx, $contract_idx, $kepco_code, $start_date, $reduce_type)
    {
        $cbl = $reduce_type == 1 ? "cbl_sr" : "cbl_dr";
        # v - 사용량, cbl - CBL, u - 감축량, q - 지시용량, r - 감축율
        $sql = " SELECT ROUND(a.reduce_qty,2) AS reduce_qty, d.h, d.m, c.finish, c.reduce_order_qty,
                       b.v,
                       ROUND(b.{$cbl},3) AS cbl,
                       CASE WHEN b.v = 0 THEN 0 ELSE ROUND(b.{$cbl} - b.v,2) END AS u,
                       CASE WHEN c.ref_reduce_idx IS NULL THEN '-' ELSE ROUND(c.reduce_order_qty / (c.duration / 15),2) END AS q,
                       CASE WHEN c.ref_reduce_idx IS NULL THEN '-' ELSE ROUND(((b.{$cbl} - b.v) / (c.reduce_order_qty / (c.duration / 15))) * 100,2) END AS r,
                       CASE WHEN c.ref_reduce_idx IS NULL THEN '-' ELSE ROUND((a.reduce_qty / a.reduce_order_qty) * 100,2) END AS reduce_rate,
                       CASE WHEN c.ref_reduce_idx IS NULL THEN '0' ELSE ROUND(b.{$cbl} - c.reduce_order_qty / (c.duration / 15),2) END AS reduce_target_v,
                       a.start_h, a.start_m, a.end_h, a.end_m
                 FROM _reduce_interval a
                 INNER JOIN _wattage_hm d
                     ON CAST(CONCAT(d.h, d.m) AS UNSIGNED) > CAST(CONCAT(a.start_h, a.start_m) AS UNSIGNED)
                     AND CAST(CONCAT(d.h, d.m) AS UNSIGNED) <= CAST(CONCAT(a.end_h, a.end_m) AS UNSIGNED)
                 RIGHT JOIN _wattage b
                     ON b.kepco_code = ?
                     AND b.ymd = ?
                     AND b.ref_contract_idx = ?
                     AND CAST(CONCAT(b.h, b.m) AS UNSIGNED) = CAST(CONCAT(d.h, d.m) AS UNSIGNED)
                     AND CAST(CONCAT(b.h, b.m) AS UNSIGNED) = CAST(CONCAT(d.h, d.m) AS UNSIGNED)
                 LEFT OUTER JOIN _reduce_contract_interval c
                     ON a.ref_reduce_idx = c.ref_reduce_idx
                     AND c.ref_contract_idx = ?
                     AND a.start_h = c.start_h
                     AND a.start_m = c.start_m
                     AND a.end_h = c.end_h
                     AND a.end_m = c.end_m
                 WHERE a.ref_reduce_idx = ?;";
        return DB::select($sql, [$kepco_code, $start_date, $contract_idx, $contract_idx, $reduce_idx]);
    }

    public function getReduceContractIntervalsFifteen($reduce_idx, $contract_idx, $kepco_code, $start_date, $reduce_type)
    {
        $cbl = $reduce_type == 1 ? "cbl_sr" : "cbl_dr";
        # v - 사용량, cbl - CBL, u - 감축량, q - 지시용량, r - 감축율
        $sql = " SELECT ROUND(a.reduce_qty,2) AS reduce_qty, d.h, d.m, c.finish, c.reduce_order_qty,
                       b.v AS v,
                       ROUND(b.{$cbl},3) AS cbl,
                       ROUND(b.{$cbl} - b.v,2) AS u,
                       CASE WHEN c.ref_reduce_idx IS NULL THEN '-' ELSE TRUNCATE(c.reduce_order_qty / (c.duration / 15)) END AS q,
                       CASE WHEN c.ref_reduce_idx IS NULL THEN '-' ELSE ROUND((b.{$cbl} - b.v) / (c.reduce_order_qty / (c.duration / 15))) * 100,2) END AS r,
                       CASE WHEN c.ref_reduce_idx IS NULL THEN '-' ELSE ROUND((a.reduce_qty / a.reduce_order_qty) * 100,2) END AS reduce_rate,
                       CASE WHEN c.ref_reduce_idx IS NULL THEN '0' ELSE ROUND(b.{$cbl} - c.reduce_order_qty / (c.duration / 15),2) END AS reduce_target_v
                 FROM _reduce_interval a
                 INNER JOIN _wattage_hm d
                     ON CAST(CONCAT(d.h, d.m) AS UNSIGNED) > CAST(CONCAT(a.start_h, a.start_m) AS UNSIGNED)
                     AND CAST(CONCAT(d.h, d.m) AS UNSIGNED) <= CAST(CONCAT(a.end_h, a.end_m) AS UNSIGNED)
                 RIGHT JOIN _wattage b
                     ON b.kepco_code in ({$kepco_code})
                     AND b.ref_contract_idx = in ({$contract_idx})
                     AND b.ymd = {$start_date}
                     AND CAST(CONCAT(b.h, b.m) AS UNSIGNED) = CAST(CONCAT(d.h, d.m) AS UNSIGNED)
                     AND CAST(CONCAT(b.h, b.m) AS UNSIGNED) = CAST(CONCAT(d.h, d.m) AS UNSIGNED)
                 LEFT OUTER JOIN _reduce_contract_interval c
                     ON a.ref_reduce_idx = c.ref_reduce_idx
                     AND c.ref_contract_idx = b.ref_contract_idx
                     AND a.start_h = c.start_h
                     AND a.start_m = c.start_m
                     AND a.end_h = c.end_h
                     AND a.end_m = c.end_m
                 WHERE a.ref_reduce_idx = {$reduce_idx};";
        return DB::select($sql);
    }

    /**
     * Excel 서식변경
     * Create by tk.kim 2017.06
     *
     * @param $sheet
     * @param $location (A1, A1:H1)
     * @param string $align (center, left, right)
     * @param string $valign
     * @param string $weight
     * @param int $size
     * @param null $color
     * @param null $font_color
     */
    public function setCellStyle($sheet, $location, $align = 'center', $valign = 'center', $weight = 'bold', $size = 10, $color = null, $font_color = null)
    {
        $sheet->cells($location, function ($cells) use ($align, $valign, $weight, $size, $color, $font_color) {
            $cells->setAlignment($align);
            $cells->setValignment($valign);
            $cells->setFontSize($size);
            $cells->setFontWeight($weight);
            if ($color) $cells->setBackground($color);
            if ($font_color) $cells->setFontColor($font_color);
            //$cells->setBorder('thin', 'thin', 'thin', 'thin');
        });
    }

    /**
     * 5분 검침기 현황 데이터 가져오기
     * Create by tk.kim 2017.06
     *
     * @return mixed
     */
    public function getDeviceData()
    {
        $adminController = new AdminController(new Request);
        $global_contractsIdx = $adminController->getPermissionContractsIdx();

        $contractState = config('eims.ContractState');
        $manufacturer = config('eims.DeviceManufacturer');
        $useFlagYN = config('eims.UseFlagOnOff');
        $select = [
            '_contract.idx',
            '_device.device',
            '_device.pt',
            '_device.ct',
            '_device.kwp',
            '_device.state',
            '_device.manufacturer',
            '_contract.name',
            '_contract.kepco_code',
            '_contract.contract_state'
        ];
        $devices = Device::select($select)
            ->leftJoin('_contract', '_device.contract', '_contract.idx')
            ->whereIn('contract', $global_contractsIdx)->get();

        foreach ($devices as $device) {
            $device->manufacturerTitle = findConfigValueName($manufacturer, $device->manufacturer);
            $device->contract_stateTitle = findConfigValueName($contractState, $device->contract_state);
            $device->stateTitle = findConfigValueName($useFlagYN, $device->state);
        }
        return $devices;
    }

    /**
     * 5분, 15분 일별 CBL, v 데이타 가져오기
     * Create by tk.kim 2017.06
     *
     * @param $s_date
     * @param $e_date
     * @param $kepco_code
     * @param $type
     * @param $idx
     * @return array
     */
    public function getWattageCBLAnalyticsData($s_date, $e_date, $kepco_code, $type, $idx)
    {
        $controller = new Controller(new Request);
        $dates = $controller->getDates($s_date, $e_date);

        $wattageCBL_V = [];
        $wattageFiveDaySelect = [
            '00_v as 01_v', '00_cbl as 01_cbl', '01_v as 02_v', '01_cbl as 02_cbl',
            '02_v as 03_v', '02_cbl as 03_cbl', '03_v as 04_v', '03_cbl as 04_cbl',
            '04_v as 05_v', '04_cbl as 05_cbl', '05_v as 06_v', '05_cbl as 06_cbl',
            '06_v as 07_v', '06_cbl as 07_cbl', '07_v as 08_v', '07_cbl as 08_cbl',
            '08_v as 09_v', '08_cbl as 09_cbl', '09_v as 10_v', '09_cbl as 10_cbl',
            '10_v as 11_v', '10_cbl as 11_cbl', '11_v as 12_v', '11_cbl as 12_cbl',
            '12_v as 13_v', '12_cbl as 13_cbl', '13_v as 14_v', '13_cbl as 14_cbl',
            '14_v as 15_v', '14_cbl as 15_cbl', '15_v as 16_v', '15_cbl as 16_cbl',
            '16_v as 17_v', '16_cbl as 17_cbl', '17_v as 18_v', '17_cbl as 18_cbl',
            '18_v as 19_v', '18_cbl as 19_cbl', '19_v as 20_v', '19_cbl as 20_cbl',
            '20_v as 21_v', '20_cbl as 21_cbl', '21_v as 22_v', '21_cbl as 22_cbl',
            '22_v as 23_v', '22_cbl as 23_cbl', '23_v as 24_v', '23_cbl as 24_cbl'
        ];

        $wattageDaySelect = [
            '01_v', '01_cbl', '02_v', '02_cbl', '03_v', '03_cbl', '04_v', '04_cbl',
            '05_v', '05_cbl', '06_v', '06_cbl', '07_v', '07_cbl', '08_v', '08_cbl',
            '09_v', '09_cbl', '10_v', '10_cbl', '11_v', '11_cbl', '12_v', '12_cbl',
            '13_v', '13_cbl', '14_v', '14_cbl', '15_v', '15_cbl', '16_v', '16_cbl',
            '17_v', '17_cbl', '18_v', '18_cbl', '19_v', '19_cbl', '20_v', '20_cbl',
            '21_v', '21_cbl', '22_v', '22_cbl', '23_v', '23_cbl', '24_v', '24_cbl'
        ];

        $wattageNullDataSet = [
            '01_v' => 0, '01_cbl' => 0, '02_v' => 0, '02_cbl' => 0, '03_v' => 0, '03_cbl' => 0, '04_v' => 0, '04_cbl' => 0,
            '05_v' => 0, '05_cbl' => 0, '06_v' => 0, '06_cbl' => 0, '07_v' => 0, '07_cbl' => 0, '08_v' => 0, '08_cbl' => 0,
            '09_v' => 0, '09_cbl' => 0, '10_v' => 0, '10_cbl' => 0, '11_v' => 0, '11_cbl' => 0, '12_v' => 0, '12_cbl' => 0,
            '13_v' => 0, '13_cbl' => 0, '14_v' => 0, '14_cbl' => 0, '15_v' => 0, '15_cbl' => 0, '16_v' => 0, '16_cbl' => 0,
            '17_v' => 0, '17_cbl' => 0, '18_v' => 0, '18_cbl' => 0, '19_v' => 0, '19_cbl' => 0, '20_v' => 0, '20_cbl' => 0,
            '21_v' => 0, '21_cbl' => 0, '22_v' => 0, '22_cbl' => 0, '23_v' => 0, '23_cbl' => 0, '24_v' => 0, '24_cbl' => 0
        ];

        foreach ($dates as $date) {
            $where = [
                'kepco_code' => $kepco_code,
                'ymd' => $date,
                'ref_contract_idx' => $idx,
            ];

            $wattageDayClass = ($type == 15) ? WattageDay::class : WattageFiveDay::class;
            $wattageDaySelect = ($type == 15) ? $wattageDaySelect : $wattageFiveDaySelect;
            $wattageDayOrigin = $wattageDayClass::select($wattageDaySelect)->where($where)->first();
            if (is_null($wattageDayOrigin)) $wattageDayOrigin = $wattageNullDataSet;
            $wattageCBL_V[] = ['date' => $date, 'data' => $wattageDayOrigin];
        }
        return array_reverse($wattageCBL_V);
    }

    /**
     * 5분, 15분 검침데이터 가져오기
     * Create by tk.kim 2017.06
     *
     * @param $s_date
     * @param $e_date
     * @param $kepco_code
     * @param $type
     * @param $idx
     * @return array
     */
    public function getContractWattageDevicesAnalyticsData($s_date, $e_date, $kepco_code, $type, $idx)
    {
        $controller = new Controller(new Request);
        $dates = $controller->getDates($s_date, $e_date);
        $wattageClass = ($type == 15) ? Wattage::class : WattageFive::class;
        $wattageHmClass = ($type == 15) ? WattageHM::class : WattageFiveHM::class;
        $device = [(object)['device' => 0]];
        if ($type == 5) $device = Device::select('device')->where('contract', $idx)->get();
        $returnData = [];
        $wattageSelectRaw = "ymd, h, m, CASE WHEN v is null THEN 0 ELSE round(v, 2) END AS v, CASE WHEN cbl is null THEN 0 ELSE round(cbl, 2) END AS cbl";
        $dates = array_reverse($dates);
        foreach ($dates as $date) {
            $dateDevice = [];
            if ($type == 5) $device = WattageFive::select('device')->distinct()->where(['kepco_code' => $kepco_code, 'ymd' => $date, 'ref_contract_idx' => $idx])->get();
            foreach ($device as $item) {
                $wattageHmSelectRaw = "'{$date}' as ymd, h, m, '0' as v, '0' as cbl";
                $where = [
                    'kepco_code' => $kepco_code,
                    'ref_contract_idx' => $idx,
                ];
                if ($type == 5) $where['device'] = $item->device;
                $wattage = $wattageClass::selectRaw($wattageSelectRaw)->where($where)->where('ymd', $date)->get()->toArray();
                if (empty($wattage)) $wattage = $wattageHmClass::selectRaw($wattageHmSelectRaw)->get()->toArray();
                $dateDevice[] = ['device' => $item->device, 'data' => $wattage];
            }
            $returnData[] = ['date' => $date, 'devices' => $dateDevice];
        }
        return $returnData;
    }

    /**
     *
     * Create by tk.kim 2017.07
     *
     * @param $contractInterval
     * @param $reduceOrderQtySum
     * @param $er_sum
     * @param $q_sum
     * @param $u_sum
     * @param $contractHourIntervals
     * @param $total_u
     * @return array
     */
    public function getContractFiveIntervalChartData($contractInterval, $reduceOrderQtySum, &$er_sum, &$q_sum, &$u_sum, &$contractHourIntervals, $total_u, $key, &$cnt, &$reduce_contract_interval_order_qty)
    {
        # v - 사용량, cbl - CBL, u - 감축량, q - 지시용량, r - 감축율, reduce_target_v - 목표감축량
        # 목표감축량 reduce_target_v = cbl - (reduce_order_qty / 12)
        # 목표사용량 reduce_target_u = cbl - reduce_target_v
        /*<!-- cbl -->
        <!-- reduce_target_v 목표사용량 -->
        <!-- v 현재사용량 -->
        <!-- left_v 잔여사용량 -->
        <!-- q 목표감축량 -->
        <!-- u 현재감축량 -->
        <!-- expected_reduce_qty 예상감축량 -->
        <!-- reduce_rate 누적감축룰 -->
        <!-- r 예상감축률 -->*/
        $return = [
            'hh' => '', 'mm' => '', 'finish' => '', 'cbl' => 0, 'reduce_target_v' => 0, 'v' => 0, 'left_v' => 0, 'q' => 0, 'u' => 0, 'expected_reduce_qty' => 0, 'r' => 0, 'reduce_rate' => 0, 'five_reduce_rate' => 0, 'end_h' => ''
        ];
        if (empty($contractHourIntervals)) $contractHourIntervals = $return;
        $return['finish'] = $contractInterval->finish;
        $return['time'] = $contractInterval->hh . ':' . $contractInterval->mm;
        $return['hh'] = $contractInterval->hh;
        $return['mm'] = $contractInterval->mm;
        $return['cbl'] = $contractInterval->cbl;
        $return['v'] = $contractInterval->v;
        $return['end_h'] = $contractInterval->end_h;
        # 목표사용량: CBL - 목표감축량
        try {
            $return['reduce_target_v'] = $contractInterval->reduce_target_v;
        } catch (ErrorException $e) {
            $return['reduce_target_v'] = 0;
        }

        # 잔여사용량:
        try {
            $return['left_v'] = round($return['reduce_target_v'] - $contractInterval->v, 2);
        } catch (ErrorException $e) {
            $return['left_v'] = 0;
        }

        # 목표감축량: 지시용량 / 12
        try {
            $tmp_reduce_contract_interval_order_qty = $reduce_contract_interval_order_qty;
            $reduce_contract_interval_order_qty = $reduce_contract_interval_order_qty - $contractInterval->q;
            $q = ($cnt == $key) ? $tmp_reduce_contract_interval_order_qty : $contractInterval->q;
            $return['q'] = $q;
        } catch (ErrorException $e) {
            $return['q'] = 0;
        }
        $cnt++;
        # 현재감축량: 잔여사용량 - 목표감축량
        $return['u'] = $this->getWattageU($return);
        $return['expected_reduce_qty'] = 0;
        # 현재감축량합
        $u_sum += $return['u'];
        # 예상감축량
        try {
            if ($return['u'] != 0) {
                $return['expected_reduce_qty'] = 0;
            } else {
                $return['expected_reduce_qty'] = round($return['q'] * ($u_sum / $q_sum), 2);
            }
            if ($return['v'] == 0) $return['expected_reduce_qty'] = round($return['q'] * (($u_sum + $er_sum) / $q_sum), 2);
        } catch (ErrorException $e) {
            $return['expected_reduce_qty'] = 0;
        }
        # 목표감축량합
        $q_sum += $return['q'];
        # 예상감축량합;
        $er_sum += $return['expected_reduce_qty'];
        # 누적감축율: (예상감축량합 + 현재감축량합) / 목표감축량 합
        $return['reduce_rate'] = $this->getWattageReduceRate($er_sum, $u_sum, $q_sum);
        # 예상감축율
//        $return['r'] = $this->getWattageR($return);
        $return['r'] = $this->getFiveReduceRate($return);
        $contractHourIntervals['time'] = $return['hh'] . ':' . $return['mm'];
        $contractHourIntervals['hh'] = $return['hh'];
        $contractHourIntervals['mm'] = $return['mm'];
        $contractHourIntervals['finish'] = $return['finish'];
        $contractHourIntervals['cbl'] += $return['cbl'];
        $contractHourIntervals['reduce_target_v'] += $return['reduce_target_v'];
        $contractHourIntervals['v'] += $return['v'];
        $contractHourIntervals['left_v'] += $return['left_v'];
        $contractHourIntervals['q'] += $return['q'];
        $contractHourIntervals['u'] += $return['u'];
        $contractHourIntervals['expected_reduce_qty'] += $return['expected_reduce_qty'];
        $contractHourIntervals['r'] = $this->getWattageR($contractHourIntervals);
        $contractHourIntervals['reduce_rate'] = $this->getWattageReduceRate($er_sum, $u_sum, $q_sum);
        $contractHourIntervals['end_h'] = $return['end_h'];
        try {
            $contractHourIntervals['cbl'] = round($contractHourIntervals['cbl'], 3);
        } catch (ErrorException $e) {
            $contractHourIntervals['cbl'] = 0;
        }
        try {
            $contractHourIntervals['reduce_target_v'] = round($contractHourIntervals['reduce_target_v'], 2);
        } catch (ErrorException $e) {
            $contractHourIntervals['reduce_target_v'] = 0;
        }
        try {
            $contractHourIntervals['v'] = round($contractHourIntervals['v'], 2);
        } catch (ErrorException $e) {
            $contractHourIntervals['v'] = 0;
        }
        try {
            $contractHourIntervals['left_v'] = round($contractHourIntervals['left_v'], 2);
        } catch (ErrorException $e) {
            $contractHourIntervals['left_v'] = 0;
        }
        try {
            $contractHourIntervals['q'] = round($contractHourIntervals['q'], 2);
        } catch (ErrorException $e) {
            $contractHourIntervals['q'] = 0;
        }
        try {
            $contractHourIntervals['u'] = round($contractHourIntervals['u'], 2);
        } catch (ErrorException $e) {
            $contractHourIntervals['u'] = 0;
        }
        try {
            $contractHourIntervals['expected_reduce_qty'] = round($contractHourIntervals['expected_reduce_qty'], 2);
        } catch (ErrorException $e) {
            $contractHourIntervals['expected_reduce_qty'] = 0;
        }
        return $return;
    }

    /**
     * 서비스 사용중 , 재 구현 확인후 삭제 예정
     * Create by tk.kim 2017.07
     *
     * @param $contractInterval
     * @param $er_sum
     * @param $q_sum
     * @param $u_sum
     * @param $type
     * @return array
     */
    public function getReduceHourIntervalChartData($contractInterval, &$er_sum, &$q_sum, &$u_sum, $type = null)
    {
        $return = [
            'h' => '', 'finish' => '', 'cbl' => 0, 'reduce_target_v' => 0, 'v' => 0, 'left_v' => 0, 'q' => 0, 'u' => 0, 'expected_reduce_qty' => 0, 'r' => 0, 'reduce_rate' => 0
        ];
        $return['finish'] = $contractInterval->finish;
        $return['h'] = $contractInterval->start_h;
        $return['cbl'] = $contractInterval->cbl;
        $return['v'] = $contractInterval->v;
        try {
            $return['reduce_target_v'] = round($contractInterval->cbl - $contractInterval->reduce_order_qty, 2);
        } catch (ErrorException $e) {
            $return['reduce_target_v'] = 0;
        }

        try {
            $return['left_v'] = round($return['reduce_target_v'] - $contractInterval->v, 2);
        } catch (ErrorException $e) {
            $return['left_v'] = 0;
        }

        $return['q'] = $contractInterval->reduce_order_qty;
        $return['u'] = $this->getWattageU($return);
        $return['expected_reduce_qty'] = 0;
        # 목표감축량합
        $q_sum += $return['q'];
        # 현재감축량합
        $u_sum += $return['u'];
        # 예상감축량
        try {
            if ($return['v'] == 0) $return['expected_reduce_qty'] = round(($u_sum / $contractInterval->reduce_order_qty) * $return['q'], 2);
            if ($u_sum == 0) $return['expected_reduce_qty'] = round($contractInterval->cbl, 2);
        } catch (ErrorException $e) {
            $return['expected_reduce_qty'] = 0;
        }

        # 예상감축량합;
        if ($return['v'] != 0) $er_sum += $return['expected_reduce_qty'];
        # 누적감축율: (예상감축량합 + 현재감축량합) / 목표감축량 합
        $return['reduce_rate'] = $this->getWattageReduceRate($er_sum, $u_sum, $q_sum);
        # 예상감축율
        $return['r'] = $this->getFiveReduceRate($return);
        if ($return['finish'] == 1) $return['reduce_rate'] = $return['r'];
        if ($type == 'REDUCE_RATE') return $return['reduce_rate'];
        return $return;
    }

    /**
     *
     * Create by tk.kim 2017.07
     *
     * @param $reduceInterval
     * @param $hourOrderQty
     * @param $er_sum
     * @param $q_sum
     * @param $u_sum
     * @param $reduceHourIntervalInfo
     * @param $reduceOrderQtyHM
     * @return array
     */
    public function getReduceIntervalChartData($reduceInterval, &$hourOrderQty, &$er_sum, &$q_sum, &$u_sum, &$reduceHourIntervalInfo, $reduceOrderQtyHM)
    {
        if (!is_array($reduceInterval)) $reduceInterval = (array)$reduceInterval;
        $return = [
            'time' => '', 'hh' => '', 'mm' => '', 'end_h' => '',
            'cbl' => 0, 'reduce_target_v' => 0, 'v' => 0,
            'left_v' => 0, 'q' => 0, 'u' => 0,
            'expected_reduce_qty' => 0, 'r' => 0, 'reduce_rate' => 0, 'finish' => null
        ];
        if (empty($reduceHourIntervalInfo)) $reduceHourIntervalInfo = $return;
        $return['time'] = $reduceInterval['hh'] . ':' . $reduceInterval['mm'];
        $return['hh'] = $reduceInterval['hh'];
        $return['finish'] = $reduceInterval['finish'];
        $return['mm'] = $reduceInterval['mm'];
        $return['cbl'] = $reduceInterval['cbl'];
        $return['v'] = $reduceInterval['v'];
        $return['end_h'] = $reduceInterval['end_h'];
        try {
            $return['reduce_target_v'] = round($reduceInterval['cbl'] - $reduceOrderQtyHM[$reduceInterval['hh'] . $reduceInterval['mm']], 2);
        } catch (ErrorException $e) {
            $return['reduce_target_v'] = 0;
        }

        try {
            $return['left_v'] = round($return['reduce_target_v'] - $reduceInterval['v'], 2);
        } catch (ErrorException $e) {
            $return['left_v'] = 0;
        }
        $return['q'] = $reduceOrderQtyHM[$reduceInterval['hh'] . $reduceInterval['mm']];
        $return['u'] = ($return['v'] != 0) ? round($return['left_v'] + $return['q'], 2) : 0;
        $return['expected_reduce_qty'] = 0;

        # 현재감축량합
        $u_sum += $return['u'];
        # 예상감축량
        try {
            if ($return['u'] != 0) {
                $return['expected_reduce_qty'] = 0;
            } else {
                $return['expected_reduce_qty'] = round($return['q'] * ($u_sum / $q_sum), 2);
            }
            if ($return['v'] == 0) $return['expected_reduce_qty'] = round($return['q'] * (($u_sum + $er_sum) / $q_sum), 2);
        } catch (ErrorException $e) {
            $return['expected_reduce_qty'] = 0;
        }
        # 목표감축량합
        $q_sum += $return['q'];
        # 1시간 단위 목표감축량합
        $hourOrderQty += $return['q'];

        # 예상감축량합;
        $er_sum += $return['expected_reduce_qty'];
        # 누적감축율: (예상감축량합 + 현재감축량합) / 목표감축량 합
        $return['reduce_rate'] = $this->getWattageReduceRate($er_sum, $u_sum, $q_sum);
        # 5분감축율
        $return['r'] = $this->getFiveReduceRate($return);
        $reduceHourIntervalInfo['time'] = $return['hh'] . ':' . $return['mm'];
        $reduceHourIntervalInfo['finish'] = $return['finish'];
        $reduceHourIntervalInfo['hh'] = $return['hh'];
        $reduceHourIntervalInfo['mm'] = $return['mm'];
        $reduceHourIntervalInfo['cbl'] += $return['cbl'];
        $reduceHourIntervalInfo['reduce_target_v'] += $return['reduce_target_v'];
        $reduceHourIntervalInfo['v'] += $return['v'];
        $reduceHourIntervalInfo['left_v'] += $return['left_v'];
        $reduceHourIntervalInfo['q'] += $return['q'];
        $reduceHourIntervalInfo['u'] += $return['u'];
        $reduceHourIntervalInfo['expected_reduce_qty'] += $return['expected_reduce_qty'];
        $reduceHourIntervalInfo['r'] = $this->getFiveReduceRate($reduceHourIntervalInfo);
        $reduceHourIntervalInfo['reduce_rate'] = $this->getWattageReduceRate($er_sum, $u_sum, $q_sum);
        $reduceHourIntervalInfo['end_h'] = $return['end_h'];
        try {
            $reduceHourIntervalInfo['cbl'] = round($reduceHourIntervalInfo['cbl'], 2);
        } catch (ErrorException $e) {
            $reduceHourIntervalInfo['cbl'] = 0;
        }
        try {
            $reduceHourIntervalInfo['reduce_target_v'] = round($reduceHourIntervalInfo['reduce_target_v'], 2);
        } catch (ErrorException $e) {
            $reduceHourIntervalInfo['reduce_target_v'] = 0;
        }
        try {
            $reduceHourIntervalInfo['v'] = round($reduceHourIntervalInfo['v'], 2);
        } catch (ErrorException $e) {
            $reduceHourIntervalInfo['v'] = 0;
        }
        try {
            $reduceHourIntervalInfo['left_v'] = round($reduceHourIntervalInfo['left_v'], 2);
        } catch (ErrorException $e) {
            $reduceHourIntervalInfo['left_v'] = 0;
        }
        try {
            $reduceHourIntervalInfo['q'] = round($reduceHourIntervalInfo['q'], 2);
        } catch (ErrorException $e) {
            $reduceHourIntervalInfo['q'] = 0;
        }
        try {
            $reduceHourIntervalInfo['u'] = round($reduceHourIntervalInfo['u'], 2);
        } catch (ErrorException $e) {
            $reduceHourIntervalInfo['u'] = 0;
        }
        try {
            $reduceHourIntervalInfo['expected_reduce_qty'] = round($reduceHourIntervalInfo['expected_reduce_qty'], 2);
        } catch (ErrorException $e) {
            $reduceHourIntervalInfo['expected_reduce_qty'] = 0;
        }
        return $return;
    }

    /**
     *
     * Create by tk.kim 2017.07
     *
     * @param $return
     * @return float|int
     */
    private function getWattageU($return)
    {
        try {
            return ($return['v'] > 0) ? round($return['left_v'] + $return['q'], 2) : 0;
        } catch (ErrorException $e) {
            return 0;
        }
    }

    /**
     *
     * Create by tk.kim 2017.07
     *
     * @param $er_sum
     * @param $u_sum
     * @param $q_sum
     * @return float|int
     */
    private function getWattageReduceRate($er_sum, $u_sum, $q_sum)
    {
        try {
            return round((($er_sum + $u_sum) / $q_sum) * 100, 2);
        } catch (ErrorException $e) {
            return 0;
        }
    }

    private function getFiveReduceRate($return)
    {
        return ($return['u'] != 0 and $return['q'] != 0) ? round((($return['u'] + $return['expected_reduce_qty']) / $return['q']) * 100, 2) : 0;
    }

    /**
     *
     * Create by tk.kim 2017.07
     *
     * @param $return
     * @return float|int
     */
    private function getWattageR($return)
    {
        try {
            return round((($return['u'] + $return['expected_reduce_qty']) / $return['q']) * 100, 2);
        } catch (ErrorException $e) {
            return 0;
        }
    }

    /**
     * 감축상태 가져오기
     * Create by tk.kim 2017.07
     *
     * @param $interval
     * @return string
     */
    public function getReduceState($interval)
    {
        $state = '?';
        if ($interval->finish == 1) $state = ($interval->reduce_order_qty > $interval->reduce_qty) ? 'X' : 'O';
        if ($interval->finish == 1 and $interval->reduce_qty == 0) $state = 'O';
        return $state;
    }

    /**
     *
     * Create by tk.kim 2017.06
     *
     * @param $contractInterval
     * @return float|int
     */
    public function getFiveSubTotalCnt($contractInterval)
    {
        $startDateTime = '2017-01-01 ' . $contractInterval->start_h . ':' . $contractInterval->start_m . ':00';
        $endDateTime = '2017-01-01 ' . $contractInterval->end_h . ':' . $contractInterval->end_m . ':00';
        $diff = Carbon::parse($startDateTime)->diffInMinutes(Carbon::parse($endDateTime));
        return $diff / 5;
    }

    /**
     *
     * Create by tk.kim 2017.06
     *
     * @param $contractInterval
     * @return float|int
     */
    public function getFifteenSubTotalCnt($contractInterval)
    {
        $startDateTime = '2017-01-01 ' . $contractInterval->start_h . ':' . $contractInterval->start_m . ':00';
        $endDateTime = '2017-01-01 ' . $contractInterval->end_h . ':' . $contractInterval->end_m . ':00';
        $diff = Carbon::parse($startDateTime)->diffInMinutes(Carbon::parse($endDateTime));
        return $diff / 15;
    }

    /**
     *
     * Create by tk.kim 2017.03
     * @param $reduce_idx
     * @param $contractsIdx
     * @return bool
     */
    public function getReduceContractIntervals($reduce_idx, $contractsIdx)
    {
        if (empty($reduce_idx)) return false;

        $selectRaw = "_req_reduce_desc.ref_req_reduce_idx,
        _req_reduce_desc.h,
        _req_reduce_desc.v AS req_order_qty,
        _req_reduce_desc.cbl AS req_cbl,
        _req_reduce.ref_contract_idx,
        _req_reduce.ref_dr_idx,
        _contract.name,
        _contract.idx,
        _contract.kepco_code,
        CASE WHEN _reduce_contract_interval.reduce_order_qty IS NULL THEN 0 ELSE _reduce_contract_interval.reduce_order_qty END AS rc_order_qty,
        CASE WHEN _reduce_contract_interval.cbl IS NULL THEN 0 ELSE _reduce_contract_interval.cbl END AS rci_cbl,
        CASE WHEN _reduce_interval.price IS NULL THEN 0 ELSE ROUND(_reduce_interval.price,2) END AS price";

        return ReqReduceDesc::selectRaw($selectRaw)
            ->leftJoin('_req_reduce', '_req_reduce_desc.ref_req_reduce_idx', '_req_reduce.idx')
            ->leftJoin('_contract', '_req_reduce.ref_contract_idx', '_contract.idx')
            ->leftJoin('_reduce_contract', '_reduce_contract.ref_req_reduce_idx', '_req_reduce.idx')
            ->leftJoin('_reduce_interval', function ($join) {
                $join->on('_reduce_interval.ref_reduce_idx', '=', '_reduce_contract.ref_reduce_idx')
                    ->on(DB::raw("CASE WHEN `_reduce_interval`.`end_h` = '00' THEN 24 ELSE `_reduce_interval`.`end_h` END"), '=', '_req_reduce_desc.h');
            })
            ->leftJoin('_reduce_contract_interval', function ($join) {
                $join->on('_reduce_contract_interval.ref_reduce_idx', '=', '_reduce_contract.ref_reduce_idx')
                    ->on('_reduce_contract_interval.ref_contract_idx', '=', '_reduce_contract.ref_contract_idx')
                    ->on('_reduce_contract_interval.start_h', '=', '_reduce_interval.start_h');
            })
            ->whereIn('_req_reduce_desc.ref_req_reduce_idx', $reduce_idx)
            ->whereIn('_contract.idx', $contractsIdx)
            ->where('_req_reduce_desc.v', '!=', 0)
            ->orderBy('h', 'asc')
            ->orderBy('ref_contract_idx', 'asc')
            ->get();
    }

    /**
     *
     * Create by tk.kim 2017.03
     * @param $reduce_idx
     * @param $contractsIdx
     * @return bool
     */
    public function getReduceContracts($reduce_idx, $contractsIdx)
    {
        if (empty($reduce_idx)) return false;
        $select = [
            '_contract.idx',
            '_contract.kepco_code',
            '_contract.name'
        ];

        return ReqReduceDesc::select($select)
            ->leftJoin('_req_reduce', '_req_reduce_desc.ref_req_reduce_idx', '_req_reduce.idx')
            ->leftJoin('_contract', '_req_reduce.ref_contract_idx', '_contract.idx')
            ->leftJoin('_reduce_contract', '_reduce_contract.ref_req_reduce_idx', '_req_reduce.idx')
            ->leftJoin('_reduce_interval', function ($join) {
                $join->on('_reduce_interval.ref_reduce_idx', '=', '_reduce_contract.ref_reduce_idx')
                    ->on('_reduce_interval.start_h', '=', '_req_reduce_desc.h');
            })
            ->leftJoin('_reduce_contract_interval', function ($join) {
                $join->on('_reduce_contract_interval.ref_reduce_idx', '=', '_reduce_contract.ref_reduce_idx')
                    ->on('_reduce_contract_interval.ref_contract_idx', '=', '_reduce_contract.ref_contract_idx')
                    ->on('_reduce_contract_interval.start_h', '=', '_reduce_interval.start_h');
            })
            ->whereIn('_req_reduce_desc.ref_req_reduce_idx', $reduce_idx)
            ->whereIn('_contract.idx', $contractsIdx)
            ->groupBy(['idx', 'kepco_code', 'name'])
            ->get();
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $date
     * @param $contract
     * @return array
     */
    public function getWattageDayStateDetailsData($date, $contract)
    {
        $sql = "
            SELECT
              distinct
              `_wattage5`.device,
              `_wattage5_hm`.hh,
              `_wattage5_hm`.mm,
              `_wattage5`.kepco_code,
              `_wattage5`.v AS wattage5_v,
              `_wattage5`.adr,
              round(`_wattage`.v, 2)  AS wattage15_v,
              round(`_wattage`.wattage5_sum, 2) as wattage5_sum,
              round(`_wattage`.diff, 2) as diff,
              `_wattage`.diff_percent,
              `_device`.state
              #'Y' as state
            FROM `_wattage5_hm`
              LEFT JOIN `_device` ON _device.contract = ? AND _device.deleted_at IS NULL
              LEFT OUTER JOIN `_wattage5` ON `_wattage5_hm`.hh = `_wattage5`.h AND `_wattage5_hm`.mm = `_wattage5`.m
                                             AND ymd = ? AND kepco_code = ?
                                             AND `_wattage5`.ref_contract_idx = _device.contract
              LEFT OUTER JOIN `_wattage` ON `_wattage5_hm`.hh = `_wattage`.h AND `_wattage5_hm`.mm = `_wattage`.m
                                            AND `_wattage`.ymd = ? AND `_wattage`.kepco_code = ? AND `_wattage`.ref_contract_idx = ?
            ;
        ";

        $bind = [$contract->idx, $date, $contract->kepco_code, $date, $contract->kepco_code, $contract->idx];

        $wattage = DB::select($sql, $bind);
        $returnWattage = $this->getWattageTime();
        if ($wattage) {
            $wattage5 = WattageFive::where([
                'kepco_code' => $contract->kepco_code,
                'ymd' => strval($date),
                'h' => 0,
                'm' => 0,
                'ref_contract_idx' => $contract->idx
            ])->first();

            $wattage15 = Wattage::where([
                'kepco_code' => $contract->kepco_code,
                'ymd' => strval($date),
                'h' => 24,
                'm' => '00',
                'ref_contract_idx' => $contract->idx
            ])->first();

            $wattage5_adr = null;
            $wattage5_v = null;
            if ($wattage5) {
                $wattage5_v = $wattage5->v;
                $wattage5_adr = $wattage5->adr;
            }
            $wattage15_v = null;
            $wattage15_sum = null;
            $wattage15_diff = null;
            $wattage15_diff_percent = null;
            if ($wattage15) {
                $wattage15_v = $wattage15->v;
                $wattage15_sum = $wattage15->wattage5_sum;
                $wattage15_diff = $wattage15->diff;
                $wattage15_diff_percent = $wattage15->diff_percent;
            }
            $wattage[] = (object)[
                'hh' => '24',
                'mm' => '00',
                'kepco_code' => $contract->kepco_code,
                'wattage5_v' => $wattage5_v,
                'adr' => $wattage5_adr,
                'wattage15_v' => $wattage15_v,
                'wattage5_sum' => $wattage15_sum,
                'diff' => $wattage15_diff,
                'diff_percent' => $wattage15_diff_percent
            ];

            $device_hh_mm_v = [];
            foreach ($wattage as $item) {
                // 모-자 참여고객은 5분검침기의 송수신 여부 state N
                // state Y ?? 뭔상관..? 다계기일 경우 합산해서 보여주기 위한 로직
//                if (isset($item->state) && $item->state == 'Y') {
                if (!isset($device_hh_mm_v[$item->hh . $item->mm])) $device_hh_mm_v[$item->hh . $item->mm] = null;
                $device_hh_mm_v[$item->hh . $item->mm] += $item->wattage5_v;
//                }
            }

            foreach ($wattage as $k => $item) {
                $key = $this->getWattageKey($item->hh, $item->mm);
                $wattage15_v = $item->wattage15_v;
                $wattage5_sum = $item->wattage5_sum;
                $diff = $item->diff;
                $diff_percent = $item->diff_percent;
                if (!is_null($wattage15_v) || !is_null($wattage5_sum) ||
                    !is_null($diff) || !is_null($diff_percent)
                ) {
                    $item->wattage15_v = null;
                    $item->wattage5_sum = null;
                    $item->diff = null;
                    $item->diff_percent = null;
                    $sKey = $key - 3;
                    //가끔 15분 데이터에 15분 단위가 아닌 05,20분등의 데이터가 들어오는데 05분 데이터면 $sKey값이 음수가 되버려서 continue로 넘겨버리게 수정.
                    if($sKey < 0) continue;
                    $returnWattage[$sKey]['wattage15_v'] = $wattage15_v;
                    $returnWattage[$sKey]['wattage5_sum'] = $wattage5_sum;
                    $returnWattage[$sKey]['diff'] = $diff;
                    $returnWattage[$sKey]['diff_percent'] = $diff_percent;
                }
                $item->wattage5_v = is_array($device_hh_mm_v) && array_key_exists($item->hh . $item->mm, $device_hh_mm_v) ? round($device_hh_mm_v[$item->hh . $item->mm], 2) : is_null($item->wattage5_v) ?: $item->wattage5_v;
                $returnWattage[$key] = (array)$item;
            }
            unset($returnWattage[288]);
        }
        return $returnWattage;
    }

    /**
     * 일별 24시간 가져오기
     * Create by tk.kim 2017.05
     */
    private function getWattageTime()
    {
        $time = [];
        $minute = 0;
        $returnWattage = [
            'hh' => 0,
            'mm' => 0,
            'kepco_code' => 0,
            'wattage5_v' => null,
            'adr' => null,
            'wattage15_v' => null,
            'wattage5_sum' => null,
            'diff' => null,
            'diff_percent' => null
        ];

        while (true) {
            if ($minute == 1440) break; /*(5 * 12 * 24)*/
            $h = Carbon::parse('2017-01-01 00:00:00')->addMinutes($minute)->format('H');
            $m = Carbon::parse('2017-01-01 00:00:00')->addMinutes($minute)->format('i');
            $minute = $minute + 5;
            $returnWattage['hh'] = $h;
            $returnWattage['mm'] = $m;
            $time[] = $returnWattage;
        }
        $time[] = $returnWattage = [
            'hh' => 24,
            'mm' => '00',
            'kepco_code' => 0,
            'wattage5_v' => null,
            'adr' => null,
            'wattage15_v' => null,
            'wattage5_sum' => null,
            'diff' => null,
            'diff_percent' => null
        ];
        return $time;
    }

    /**
     * 일별 24시간 배열 키 찾기
     * Create by tk.kim 2017.05
     * @param $hour
     * @param $minute
     * @return int
     */
    private function getWattageKey($hour, $minute)
    {
        $minuteCnt = (intval($hour) * 12) + (intval($minute) / 5);
        return 288 - (1440 - ($minuteCnt * 5)) / 5;
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $date
     * @param $contract
     * @param $select
     * @param $need_lpad
     * @return mixed
     */
    public function getWattageFive($date, $contract, $select, $need_lpad = false)
    {
        $selectType = ($need_lpad == true) ? 'selectRaw' : 'select';
        return WattageFive::$selectType($select)->where(['kepco_code' => $contract->kepco_code, 'ymd' => $date, 'ref_contract_idx' => $contract->idx])->get();
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $date
     * @param $contract
     * @param $select
     * @return mixed
     */
    public function getWattage($date, $contract, $select)
    {
        return Wattage::select($select)->where(['kepco_code' => $contract->kepco_code, 'ymd' => strval($date), 'ref_contract_idx' => $contract->idx])->get();
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param int $reduce_idx
     * @return array
     */
    public function getReduceInfoFromWattage($reduce_idx)
    {
        $reduce_contracts_idx = ReduceContract::select('ref_contract_idx')->where('ref_reduce_idx', $reduce_idx)->get();
        if (is_null($reduce_contracts_idx)) return 0;

        $reduce = Reduce::find($reduce_idx);
        $ymd = Carbon::createFromTimestamp($reduce->start_date)->format('Ymd');
        $cbl = ($reduce->reduce_type == 1) ? 'cbl_sr' : 'cbl_dr';
        $wattage_table = '_wattage';
        $selectRaw = "
                ROUND(SUM({$wattage_table}.{$cbl}), 3) as cbl,
                ROUND(SUM({$wattage_table}.v), 2) as v,
                ROUND(SUM(CASE WHEN {$wattage_table}.v > 0 THEN {$wattage_table}.{$cbl} - {$wattage_table}.v ELSE 0 END), 2) AS reduce_qty";

        $wattage = Wattage::selectRaw($selectRaw)
            ->leftJoin('_contract', '_wattage.kepco_code', '_contract.kepco_code')
            ->leftJoin('_reduce_contract_interval', function ($join) use ($wattage_table) {
                # CONCAT(a.h, a.m) > CONCAT(b.start_h, b.start_m) and CONCAT(a.h, a.m) <= CONCAT(b.end_h, b.end_m)
                $join->on(
                    DB::raw("CONCAT({$wattage_table}.h, {$wattage_table}.m)"),
                    '>',
                    DB::raw("CONCAT(_reduce_contract_interval.start_h, _reduce_contract_interval.start_m)")
                );
                $join->on(
                    DB::raw("CONCAT({$wattage_table}.h, {$wattage_table}.m)"),
                    '<=',
                    DB::raw("CONCAT(_reduce_contract_interval.end_h, _reduce_contract_interval.end_m)")
                )
                    ->on('_wattage.kepco_code', '=', '_contract.kepco_code')
                    ->on('_wattage.ref_contract_idx', '=', '_contract.idx')
                    ->on('_contract.idx', '=', '_reduce_contract_interval.ref_contract_idx');
            })
            ->where([
                'ymd' => $ymd,
                '_reduce_contract_interval.ref_reduce_idx' => $reduce_idx
            ])
            ->whereIn('_reduce_contract_interval.ref_contract_idx', $reduce_contracts_idx)
            ->first();
        return $wattage;
    }

    /**
     *
     * Create by tk.kim 2017.10
     *
     * @param array $reduce_idxs
     * @param array $reduce_contract_idxs
     * @return array
     */
    public function getReduceInfoFromWattageForReduceDrReport($reduce_idxs, $reduce_contract_idxs)
    {
        $wattage_table = '_wattage';
        $selectRaw = "
                ROUND(SUM({$wattage_table}.cbl_dr), 3) as cbl,
                ROUND(SUM({$wattage_table}.v), 2) as v,
                ROUND(SUM(CASE WHEN {$wattage_table}.v > 0 THEN {$wattage_table}.cbl_dr - {$wattage_table}.v ELSE 0 END), 2) AS reduce_qty";

        $wattage = Wattage::selectRaw($selectRaw)
            ->leftJoin('_contract', '_wattage.kepco_code', '_contract.kepco_code')
            ->leftJoin('_reduce_contract_interval', function ($join) use ($wattage_table) {
                # CONCAT(a.h, a.m) > CONCAT(b.start_h, b.start_m) and CONCAT(a.h, a.m) <= CONCAT(b.end_h, b.end_m)
                $join->on(
                    DB::raw("CONCAT({$wattage_table}.h, {$wattage_table}.m)"),
                    '>',
                    DB::raw("CONCAT(_reduce_contract_interval.start_h, _reduce_contract_interval.start_m)")
                )
                    ->on(
                        DB::raw("CONCAT({$wattage_table}.h, {$wattage_table}.m)"),
                        '<=',
                        DB::raw("CONCAT(_reduce_contract_interval.end_h, _reduce_contract_interval.end_m)")
                    )
                    ->on('_wattage.kepco_code', '=', '_contract.kepco_code')
                    ->on('_wattage.ref_contract_idx', '=', '_contract.idx')
                    ->on('_contract.idx', '=', '_reduce_contract_interval.ref_contract_idx');
            })
            ->whereIn('_reduce_contract_interval.ref_reduce_idx', $reduce_idxs)
            ->whereIn('_reduce_contract_interval.ref_contract_idx', $reduce_contract_idxs)
            ->first();
        return $wattage;
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param int $reduce_idx
     * @param int $no
     * @return array
     */
    public function getReduceInfoFromWattageByIntervalNo($reduce_idx, $no)
    {
        $reduce_contracts_idx = ReduceContract::select('ref_contract_idx')->where('ref_reduce_idx', $reduce_idx)->get();
        if (is_null($reduce_contracts_idx)) return 0;

        $reduce = Reduce::find($reduce_idx);
        $ymd = Carbon::createFromTimestamp($reduce->start_date)->format('Ymd');
        $cbl = ($reduce->reduce_type == 1) ? 'cbl_sr' : 'cbl_dr';
        $wattage_table = '_wattage';
        $selectRaw = "
                ROUND(SUM({$wattage_table}.{$cbl}), 3) as cbl,
                ROUND(SUM({$wattage_table}.v), 2) as v,
                ROUND(SUM({$wattage_table}.{$cbl} - {$wattage_table}.v), 2) AS reduce_qty";

        $wattage = Wattage::selectRaw($selectRaw)
            ->leftJoin('_contract', '_wattage.kepco_code', '_contract.kepco_code')
            ->leftJoin('_reduce_interval', function ($join) use ($wattage_table) {
                $join->on(
                    DB::raw("CONCAT({$wattage_table}.h, {$wattage_table}.m)"),
                    '>',
                    DB::raw("CONCAT(_reduce_interval.start_h, _reduce_interval.start_m)")
                )->on(
                    DB::raw("CONCAT({$wattage_table}.h, {$wattage_table}.m)"),
                    '<=',
                    DB::raw("CONCAT(_reduce_interval.end_h, _reduce_interval.end_m)")
                )->on('_wattage.ref_contract_idx', '=', '_contract.idx');
            })
            ->where(['ymd' => $ymd, '_reduce_interval.ref_reduce_idx' => $reduce_idx, '_reduce_interval.no' => $no])
            ->whereIn('_contract.idx', $reduce_contracts_idx)
            ->first();
        return $wattage;
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param int $reduce_idx
     * @param int $contract_idx
     * @return array
     */
    public function getReduceInfoFromWattageByContractIdx($reduce_idx, $contract_idx)
    {
        $reduce_contracts = ReduceContract::select(['_reduce_contract.ref_reduce_idx', '_reduce_contract.ref_contract_idx', '_reduce.idx', '_reduce.start_date', '_reduce.reduce_type', '_reduce_contract.reduce_order_qty', '_reduce_contract.duration'])
            ->leftJoin('_reduce', '_reduce_contract.ref_reduce_idx', '_reduce.idx')
            ->where(['ref_reduce_idx' => $reduce_idx, 'ref_contract_idx' => $contract_idx])
            ->first();

        if (is_null($reduce_contracts)) return 0;
        $ymd = Carbon::createFromTimestamp($reduce_contracts->start_date)->format('Ymd');
        $cbl = ($reduce_contracts->reduce_type == 1) ? 'cbl_sr' : 'cbl_dr';
        $wattage_table = '_wattage';
        $selectRaw = "
            ROUND(SUM({$wattage_table}.{$cbl}), 3) as cbl,
            ROUND(SUM({$wattage_table}.v), 2) as v,
            ROUND(SUM(CASE WHEN {$wattage_table}.v > 0 THEN {$wattage_table}.{$cbl} - {$wattage_table}.v ELSE 0 END), 2) AS reduce_qty
        ";

        $wattage = Wattage::selectRaw($selectRaw)
            ->leftJoin('_contract', '_wattage.kepco_code', '_contract.kepco_code')
            ->leftJoin('_reduce_contract_interval', function ($join) use ($wattage_table) {
                $join->on(
                    DB::raw("CONCAT({$wattage_table}.h, {$wattage_table}.m)"),
                    '>',
                    DB::raw("CONCAT(_reduce_contract_interval.start_h, _reduce_contract_interval.start_m)")
                )
                    ->on(
                        DB::raw("CONCAT({$wattage_table}.h, {$wattage_table}.m)"),
                        '<=',
                        DB::raw("CONCAT(_reduce_contract_interval.end_h, _reduce_contract_interval.end_m)")
                    )
                    ->on('_wattage.kepco_code', '=', '_contract.kepco_code')
                    ->on('_wattage.ref_contract_idx', '=', '_contract.idx')
                    ->on('_contract.idx', '=', '_reduce_contract_interval.ref_contract_idx');
            })
            ->where(['ymd' => $ymd, '_reduce_contract_interval.ref_reduce_idx' => $reduce_idx])
            ->where('_reduce_contract_interval.ref_contract_idx', $contract_idx)
            ->first();
        $wattage->reduce_order_qty = $reduce_contracts->reduce_order_qty;
        $wattage->duration = $reduce_contracts->duration;
        $wattage->reduce_type = $reduce_contracts->reduce_type;
        $wattage->start_date = $reduce_contracts->start_date;
        $wattage->rate = $this->getReducePercent($wattage);
        return $wattage;
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param int $reduce_idx
     * @param int $contract_idx
     * @param int $no
     * @return array
     */
    public function getReduceInfoFromWattageByContractIdxAndNo($reduce_idx, $contract_idx, $no)
    {
        $reduce_contracts = ReduceContract::select(['_reduce_contract.ref_reduce_idx', '_reduce_contract.ref_contract_idx', '_reduce.idx', '_reduce.start_date', '_reduce.reduce_type'])
            ->leftJoin('_reduce', '_reduce_contract.ref_reduce_idx', '_reduce.idx')
            ->where(['ref_reduce_idx' => $reduce_idx, 'ref_contract_idx' => $contract_idx])
            ->first();

        if (is_null($reduce_contracts)) return 0;
        $ymd = Carbon::createFromTimestamp($reduce_contracts->start_date)->format('Ymd');
        $cbl = ($reduce_contracts->reduce_type == 1) ? 'cbl_sr' : 'cbl_dr';
        $wattage_table = '_wattage';
        $selectRaw = "
        ROUND(SUM({$wattage_table}.{$cbl}), 3) as cbl,
        ROUND(SUM({$wattage_table}.v), 2) as v,
        ROUND(SUM(CASE WHEN {$wattage_table}.v > 0 THEN {$wattage_table}.{$cbl} - {$wattage_table}.v ELSE 0 END), 2) AS reduce_qty";

        $wattage = Wattage::selectRaw($selectRaw)
            ->leftJoin('_contract', '_wattage.kepco_code', '_contract.kepco_code')
            ->leftJoin('_reduce_contract_interval', function ($join) use ($wattage_table) {
                $join->on(
                    DB::raw("CONCAT({$wattage_table}.h, {$wattage_table}.m)"),
                    '>',
                    DB::raw("CONCAT(_reduce_contract_interval.start_h, _reduce_contract_interval.start_m)")
                )
                    ->on(
                        DB::raw("CONCAT({$wattage_table}.h, {$wattage_table}.m)"),
                        '<=',
                        DB::raw("CONCAT(_reduce_contract_interval.end_h, _reduce_contract_interval.end_m)")
                    )
                    ->on('_wattage.kepco_code', '=', '_contract.kepco_code')
                    ->on('_wattage.ref_contract_idx', '=', '_contract.idx')
                    ->on('_contract.idx', '=', '_reduce_contract_interval.ref_contract_idx');
            })
            ->where(['ymd' => $ymd, '_reduce_contract_interval.ref_reduce_idx' => $reduce_idx])
            ->where('_reduce_contract_interval.ref_contract_idx', $contract_idx)
            ->where('_reduce_contract_interval.no', $no)
            ->first();
        return $wattage;
    }

    public function getReduceFromWattageByContract($reduce_idx = 0, $contract_idx = 0, $intervalNo = 0) {

        $selectRaw = "
            round(sum(_wattage.cbl_sr), 3) as cbl_sr,
            round(sum(_wattage.cbl_dr), 3) as cbl_dr,
            round(sum(_wattage.v), 3)      as v
        ";

        $wattage = Wattage::selectRaw($selectRaw)
            ->leftJoin('_contract', '_wattage.kepco_code', '_contract.kepco_code')
            ->leftJoin('_reduce_contract_interval', function ($join) {
                $join->on(
                    DB::raw("CONCAT(_wattage.h, _wattage.m)"),
                    '>',
                    DB::raw("CONCAT(_reduce_contract_interval.start_h, _reduce_contract_interval.start_m)")
                )
                    ->on(
                        DB::raw("CONCAT(_wattage.h, _wattage.m)"),
                        '<=',
                        DB::raw("CONCAT(_reduce_contract_interval.end_h, _reduce_contract_interval.end_m)")
                    )
                    ->on('_wattage.kepco_code', '=', '_contract.kepco_code')
                    ->on('_wattage.ref_contract_idx', '=', '_contract.idx')
                    ->on('_contract.idx', '=', '_reduce_contract_interval.ref_contract_idx');
            })
            ->leftJoin("_reduce", "_reduce.idx", "_reduce_contract_interval.ref_reduce_idx")
            ->where('_reduce_contract_interval.ref_reduce_idx', $reduce_idx)
            ->where('_reduce_contract_interval.ref_contract_idx', $contract_idx)
            ->where('_reduce_contract_interval.no', $intervalNo)
            ->whereRaw("_wattage.ymd = date_format(from_unixtime(_reduce.start_date), '%Y%m%d')")
            ->first();

        return $wattage;
    }

    public function getReduceFromWattageByDr($reduce_idx = 0, $hour = 0) {

        $reduce_contracts_idx = ReduceContract::select('ref_contract_idx')->where('ref_reduce_idx', $reduce_idx)->get();
        if (is_null($reduce_contracts_idx)) return 0;

        $reduce = Reduce::find($reduce_idx);
        $ymd = Carbon::createFromTimestamp($reduce->start_date)->format('Ymd');

        $selectRaw = "
                ROUND(SUM(_wattage.cbl_sr), 3) as cbl_sr,
                ROUND(SUM(_wattage.cbl_dr), 3) as cbl_dr,
                ROUND(SUM(_wattage.v), 2) as v
            ";

        $wattage = Wattage::selectRaw($selectRaw)
            ->leftJoin('_contract', '_wattage.kepco_code', '_contract.kepco_code')
            ->leftJoin('_reduce_interval', function ($join) {
                $join->on(
                    DB::raw("CONCAT(_wattage.h, _wattage.m)"),
                    '>',
                    DB::raw("CONCAT(_reduce_interval.start_h, _reduce_interval.start_m)")
                )->on(
                    DB::raw("CONCAT(_wattage.h, _wattage.m)"),
                    '<=',
                    DB::raw("CONCAT(_reduce_interval.end_h, _reduce_interval.end_m)")
                )->on('_wattage.ref_contract_idx', '=', '_contract.idx');
            })
            ->where(['ymd' => $ymd, '_reduce_interval.ref_reduce_idx' => $reduce_idx, '_reduce_interval.start_h' => $hour])
            ->whereIn('_contract.idx', $reduce_contracts_idx)
            ->first();
        return $wattage;
    }

    /**
     * @param $reduce_idx
     * @param $wattage_overwrite bool wattage 관련 테이블 DELETE & INSERT 유무 (default : true)
     * @throws Exception
     */
    public function updateCBLSR($reduce_idx, $wattage_overwrite = true)
    {
        if (!$reduce_idx) throwException(new Exception());

        // 시작 날짜
        $sDateTime = Reduce::select('start_date')->find($reduce_idx)->start_date;
        if (is_null($sDateTime)) throwException(new Exception());
        if (($sDateTime - Carbon::parse('2020-01-01 00:00:01')->timestamp) > 0) return $this->updateNewCBLSR($reduce_idx, $wattage_overwrite);
        $sDate = Carbon::createFromTimestamp($sDateTime)->format('Ymd');
        $sDateTime = Carbon::createFromTimestamp($sDateTime)->toDateTimeString();

        // 감축이벤트별 인터벌
        $reduceIntervals = ReduceInterval::select('end_h')->where(['ref_reduce_idx' => $reduce_idx])->get();

        // 감축이벤트에 참여한 참여고객 목록 조회
        $contracts = ReduceContract::select(['_contract.idx', '_contract.kepco_code', '_contract.cbl'])
            ->join('_contract', '_reduce_contract.ref_contract_idx', '_contract.idx')
            ->where(['_reduce_contract.ref_reduce_idx' => $reduce_idx])
            ->get();

        $select = ['ymd'];
        $wattageMinutes = ['15', '30', '45', '00'];
        $reduceIntervals = $reduceIntervals->toArray();
        $tmp_hour_interval = [];
        // 감축이벤트의 인터벌별 종료시간 목록
        foreach ($reduceIntervals as $item) {
            $tmp_hour_interval[] = $item['end_h'];
        }
        // 00시는 24로 변경
        foreach ($reduceIntervals as $reduceInterval) {
            if ($reduceInterval['end_h'] == '00') {
                $select[] = "24_v";
                $select[] = "24_v as s";
                continue;
            }
            $select[] = "{$reduceInterval['end_h']}_v";
            $select[] = "{$reduceInterval['end_h']}_v as s";
        }
        // 종료시간별 합산 쿼리 준비
        $select[] = DB::raw("ROUND(" . implode("_v + ", $tmp_hour_interval) . "_v, 2) as s");

        // 국가공휴일 조회
        $codes = Code::select('name')->where(['ref_idx' => 56])->get();
        if ($codes) $codes = $codes->toArray();
        // 참여고객별 루프
        foreach ($contracts as $contract) {
            // 사용량 데이터 조회 조건 준비
            $where = [
                ['ref_contract_idx', '=', $contract->idx],
                ['kepco_code', '=', $contract->kepco_code],
                ['ymd', '<', $sDate],
                ['day_week', '!=', 1],
                ['day_week', '!=', 7],
            ];

            // cbl 타입별 limit 설정
            $limit = $contract->cbl == 49 ? 6 : 11;

            // 일별 사용량 조회
            $wattages = WattageDay::select($select)->where($where)->whereNotIn('ymd', $codes)->orderBy('ymd', 'desc')->limit($limit)->get();
            $wattages = $wattages->toArray();
            //입찰일 빼기 (뺀위 1개)
            array_shift($wattages);

            //정렬
            usort($wattages, function ($a, $b) {
                return $a['s'] - $b['s'];
            });
            if ($contract->cbl == 49) { // MAX 4/5
                array_shift($wattages);
            } else if ($contract->cbl == 50) { // MID 6/10
                array_shift($wattages);
                array_shift($wattages);
                array_pop($wattages);
                array_pop($wattages);
            }
            $arrayH = [];
            foreach ($reduceIntervals as $reduceInterval) {
                $arrayH[] = $reduceInterval['end_h'] . '_v';
            }


//            \Log::debug("==1========");
//            \Log::debug($wattages);
//            \Log::debug("==2========");
//            \Log::debug($arrayH);
//            \Log::debug("==3========");
            // 시간별 평균
            $averages = array_map('array_average', array_columns($wattages, $arrayH));
//            \Log::debug($averages);
//            \Log::debug("==4========");
            // 검침기 조회
            $device = Device::select('device')->leftJoin('_contract', '_device.contract', '_contract.idx')->where(['_contract.kepco_code' => $contract->kepco_code])->get();

            // 시간별 평균
            foreach ($averages as $key => $val) {
                // 시작 시간이 미래인지 여부
                $isFuture = (Carbon::now()->timestamp < Carbon::parse($sDateTime)->timestamp) ? true : false;
                // 계획감축은 시작 시간이 항상 미래이고, 사용량 덮어쓰기가 기본으로 true
                if ($isFuture && $wattage_overwrite === true) {
                    // 시작 시간
                    $hour = Carbon::parse($sDate)->format("Y-m-d {$key}:00:00");
                    $hour = Carbon::parse($hour)->subHour()->format('H');
                    // kepco code
                    $kepco_code = $contract->kepco_code;
                    // 요일 번호
                    $dayOfWeek = Carbon::parse($sDate)->dayOfWeek + 1;
                    // 검침기별 루프
                    foreach ($device as $item) {
                        // 계획감축 시간에 해당하는 5분 사용량 삭제 조건 준비
                        $where = [
                            'kepco_code' => $kepco_code,
                            'device' => $item->device,
                            'ymd' => $sDate,
                            'h' => $hour,
                            'ref_contract_idx' => $contract->idx,
                        ];
                        // 5분 사용량 삭제
                        WattageFive::where($where)->delete();
                        $insertAll = [];
                        // 계획감축 시간에 해당하는 cbl 입력을 위한 5분 사용량 입력 쿼리 준비
                        for ($i = 0; $i < 60; $i += 5) {
                            $insertAll[] = [
                                'ref_contract_idx' => $contract->idx,
                                'kepco_code' => $kepco_code,
                                'device' => $item->device,
                                'ymd' => $sDate,
                                'h' => $hour,
                                'm' => $i,
                                'day_week' => $dayOfWeek,
                                'v' => 0,
                                'adr' => 1,
                                'reg_date' => Carbon::now()->timestamp
                            ];
                        }
                        // 5분 사용량 입력
                        WattageFive::insert($insertAll);
                    }

                    $wattageInsertAll = [];
                    // 15분 데이터 간격 별 루프 15, 30, 45, 00
                    foreach ($wattageMinutes as $wattageMinute) {
                        // 정각 시간값
                        if ($wattageMinute == '00') $hour = $key;
                        // 계획감축 시간대 15분 데이터 한 건 조회
                        $wattageModel = Wattage::where([
                            'kepco_code' => $kepco_code,
                            'ymd' => strval($sDate),
                            'h' => $hour,
                            'm' => $wattageMinute,
                            'ref_contract_idx' => $contract->idx
                        ])->first();

                        // 조회된 값이 없으면 입력쿼리 준비
                        if (is_null($wattageModel)) {
                            $wattageInsertAll[] = [
                                'ref_contract_idx' => $contract->idx,
                                'kepco_code' => $kepco_code,
                                'ymd' => $sDate,
                                'day_week' => $dayOfWeek,
                                'h' => $hour,
                                'm' => $wattageMinute,
                                'v' => 0
                            ];
                            continue;
                        }
                        // 조회된 값이 있으면 다시 초기값으로 저장
                        $wattageModel->kepco_code = $kepco_code;
                        $wattageModel->ymd = $sDate;
                        $wattageModel->day_week = $dayOfWeek;
                        $wattageModel->h = $hour;
                        $wattageModel->m = $wattageMinute;
                        $wattageModel->v = 0;
                        $wattageModel->save();
                    }
                }
                // 15분 데이터 입력
                if (!empty($wattageInsertAll)) Wattage::insert($wattageInsertAll);
                $where = ['kepco_code' => $contract->kepco_code, 'ymd' => $sDate, 'ref_contract_idx' => $contract->idx];
                $update = [$key . '_cbl_sr' => $val];
                WattageDay::where($where)->update($update);
                $whereRaw = "CAST(CONCAT(h, m) AS UNSIGNED) > " . (intval($key . "00") - 100) . " AND CAST(CONCAT(h, m) AS UNSIGNED) <= " . intval($key . "00") . "";
                $update = ['cbl_sr' => round($val / 4, 2)];
                Wattage::where($where)->whereRaw($whereRaw)->update($update);
                $deviceCnt = count($device);
                $whereRaw = "h >= " . (intval($key) - 1) . " AND h < " . intval($key);
                $update = ['cbl_sr' => round($val / (12 * $deviceCnt), 2)];
                WattageFive::where($where)->whereRaw($whereRaw)->update($update);
            }
        }
    }

    public function updateNEWCBLSR($reduce_idx, $wattage_overwrite = true){

        $sDateTime = Reduce::select('start_date')->find($reduce_idx)->start_date;
        if (is_null($sDateTime)) throwException(new Exception());

        $sDate = Carbon::createFromTimestamp($sDateTime)->format('Ymd');
        $sDateTime = Carbon::createFromTimestamp($sDateTime)->toDateTimeString();

        $reduceIntervals = ReduceInterval::select('end_h')->where(['ref_reduce_idx' => $reduce_idx])->get();

        $contracts = ReduceContract::select(['_contract.idx', '_contract.kepco_code', '_contract.cbl'])
            ->join('_contract', '_reduce_contract.ref_contract_idx', '_contract.idx')
            ->where(['_reduce_contract.ref_reduce_idx' => $reduce_idx])
            ->get();

        $select = ['ymd'];
        $wattageMinutes = ['15', '30', '45', '00'];
        $reduceIntervals = $reduceIntervals->toArray();
        $end_h = [];

        // 종료 시간
        foreach ($reduceIntervals as $interval) {
            if ($interval['end_h'] == '00') {
                $select[] = "24_v";
                $end_h[] = "24";
                continue;
            }

            $select[] = "{$interval['end_h']}_v";
            $end_h[] = $interval['end_h'];
        }

        $eDateTime = Carbon::parse($sDateTime)->subMonths(2)->format('Ymd');

        // 공휴일
        $holidays =
            Code::select('name')
                ->where(['ref_idx' => 56])
                ->where('name', '>=', $eDateTime)
                ->get();

        if ($holidays) $holidays = $holidays->toArray();

        foreach ($contracts as $contract) {
            $where = [
                ['ref_contract_idx', '=', $contract->idx],
                ['kepco_code', '=', $contract->kepco_code],
                ['ymd', '<', $sDate],
                ['day_week', '<>', 1],
                ['day_week', '<>', 7],
            ];

            $limit = $contract->cbl == 49 ? 6 : 11;

            $wattages = WattageDay::select($select)->where($where)->whereNotIn('ymd', $holidays)->orderBy('ymd', 'desc')->limit($limit)->get();
            $wattages = $wattages->toArray();

            //입찰일 빼기 (뺀위 1개)
            array_shift($wattages);

            if (empty($wattages)) {
                // 5분데이터 없음 확인 필요함
                continue;
            }

            $usage = [];
            // 사용량의 key => 시간
            /** 데이터 구조
             *  h => array(
             *      '20191201' => '사용량',
             *      '20191202' => '사용량',
             *      '20191203'  => '사용량'
             *  )
             */
            // end_h
            foreach ($end_h as $h){
                foreach ($wattages as $wattage) {
                    $usage[$h][$wattage['ymd']] = $wattage[$h . '_v'];
                }
            }

            if (empty($usage)) {
                // 로그 남겨놔야함
                continue;
            }

            // 시간별 계산
            foreach ($usage as $hour => $value) {

                // value 값으로 오름차순 한값
                asort($value);

                if ($contract->cbl == 49) { // MAX 4/5
                    $value = array_slice($value, 1, count($value), true);
                } else if ($contract->cbl == 50 ) {  // MAX 6/10
                    $value = array_slice($value, 2, count($value), true);
                    array_pop($value);
                    array_pop($value);
                }

                $average = 0;
                $average = round(array_sum($value) / count($value), 4);

                $device = Device::select('device')->leftJoin('_contract', '_device.contract', '_contract.idx')->where(['_contract.kepco_code' => $contract->kepco_code])->get();

                $wattageInsertAll = [];
                $isFuture = (Carbon::now()->timestamp < Carbon::parse($sDateTime)->timestamp) ? true : false;

                if ($isFuture && $wattage_overwrite === true) {
                    $h = Carbon::parse($sDate)->format("Y-m-d {$hour}:00:00");
                    $h = Carbon::parse($h)->subHour()->format('H');

                    $kepco_code = $contract->kepco_code;
                    $dayOfWeek = Carbon::parse($sDate)->dayOfWeek + 1;

                    foreach ($device as $item) {
                        $where = [
                            'kepco_code' => $kepco_code,
                            'device' => $item->device,
                            'ymd' => $sDate,
                            'h' => $h,
                            'ref_contract_idx' => $contract->idx,
                        ];
                        WattageFive::where($where)->delete();
                        $insertAll = [];
                        for ($i = 0; $i < 60; $i += 5) {
                            $insertAll[] = [
                                'ref_contract_idx' => $contract->idx,
                                'kepco_code' => $kepco_code,
                                'device' => $item->device,
                                'ymd' => $sDate,
                                'h' => $h,
                                'm' => $i,
                                'day_week' => $dayOfWeek,
                                'v' => 0,
                                'adr' => 1,
                                'reg_date' => Carbon::now()->timestamp
                            ];
                        }
                        WattageFive::insert($insertAll);
                    }

                    $wattageInsertAll = [];
                    foreach ($wattageMinutes as $wattageMinute) {
                        if ($wattageMinute == '00') $h = $hour;
                        $wattageModel = Wattage::where([
                            'kepco_code' => $kepco_code,
                            'ymd' => strval($sDate),
                            'h' => $h,
                            'm' => $wattageMinute,
                            'ref_contract_idx' => $contract->idx
                        ])->first();

                        if (is_null($wattageModel)) {
                            $wattageInsertAll[] = [
                                'ref_contract_idx' => $contract->idx,
                                'kepco_code' => $kepco_code,
                                'ymd' => $sDate,
                                'day_week' => $dayOfWeek,
                                'h' => $h,
                                'm' => $wattageMinute,
                                'v' => 0
                            ];
                            continue;
                        }
                        $wattageModel->kepco_code = $kepco_code;
                        $wattageModel->ymd = $sDate;
                        $wattageModel->day_week = $dayOfWeek;
                        $wattageModel->h = $h;
                        $wattageModel->m = $wattageMinute;
                        $wattageModel->v = 0;
                        $wattageModel->save();
                    }
                }
                if (!empty($wattageInsertAll)) {
                    Wattage::insert($wattageInsertAll);
                }

                $update = [];

                $where = ['kepco_code' => $contract->kepco_code, 'ymd' => $sDate, 'ref_contract_idx' => $contract->idx];
                $update[$hour . '_cbl_sr'] = $average;

                WattageDay::where($where)->update($update);

                try {
                    $wattageCblAvg = round(($average / (60 / 15)), 4);
                } catch (Exception $e) {
                    $wattageCblAvg = 0;
                }

                $updateCount = 1;
                $whereRaw = "CAST(CONCAT(h, m) AS UNSIGNED) > " . (intval($hour . "00") - 100) . " AND CAST(CONCAT(h, m) AS UNSIGNED) <= " . intval($hour . "00") . "";

                // query
                $query = "insert into _wattage(idx, cbl_sr) values";
                $wattage_values = [];
                Wattage::where($where)->whereRaw($whereRaw)->each(function ($item) use($average, &$updateCount, $wattageCblAvg, &$wattage_values){
                    $disValue = ($average - ($updateCount * $wattageCblAvg));
                    if ($disValue < 0) {
                        $wattageCblAvg += $disValue;
                    }

                    $item->cbl_sr = $wattageCblAvg;
                    $wattage_values[] = "({$item->idx}, {$item->cbl_sr})";

                    $updateCount++;
                });

                if (!empty($wattage_values)) {
                    $value = join(",", $wattage_values);
                    $query = $query . $value . " ON DUPLICATE KEY UPDATE cbl_sr = VALUES(cbl_sr)";
                    DB::statement($query);
                }

                $deviceCnt = count($device);
                $whereRaw = "h >= " . (intval($hour) - 1) . " AND h < " . intval($hour);

                if ($deviceCnt > 0) {
                    try {
                        $wattageFiveCblAvg = round(($wattageCblAvg / ($deviceCnt * 3)), 4);
                    } catch (Exception $e) {
                        $wattageFiveCblAvg = 0;
                    }
                }

                $updateCount = 0;
                $wattage_five_values = [];
                $wattage_five_query = "insert into _wattage5(idx, kepco_code, device, cbl_sr) values";
                WattageFive::where($where)->whereRaw($whereRaw)->each(function ($item) use ($average, $wattageFiveCblAvg, &$updateCount, &$wattage_five_values) {
                    $disValue = ($average - ($updateCount * $wattageFiveCblAvg));
                    if ($disValue < 0) {
                        $wattageFiveCblAvg += $disValue;
                    }

                    $item->cbl_sr = $wattageFiveCblAvg;
                    $wattage_five_values[] = "({$item->idx}, '{$item->kepco_code}', '{$item->device}', {$item->cbl_sr})";
                    $updateCount++;
                });

                if (!empty($wattage_five_values)) {
                    $wattage_five_query = $wattage_five_query . join (",", $wattage_five_values) . " ON DUPLICATE KEY UPDATE cbl_sr = values(cbl_sr)";
                    DB::statement($wattage_five_query);
                }
            }
        }
    }


    /**
     * RRMSE Crawl 계정 정보 매칭
     * @return bool ID/PW 실패
     */
    public function rrmse_crawl_account_check($user)
    {
        $url = 'https://pccs.kepco.co.kr/iSmart/cm/login.do?method=execute';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0; rv:11.0) like Gecko');
        curl_setopt($ch, CURLOPT_REFERER, 'https://pccs.kepco.co.kr/iSmart/jsp/cm/login/main.jsp');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($user));

        curl_setopt($ch, CURLOPT_HEADER, 1);//헤더를 포함한다
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $html = curl_exec($ch);

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML($html);
        $xpath = new DOMXPath($doc);

        return $xpath->query('#waiting')->length > 0 ? false : true;
    }

    /**
     * 수요자원 월별 정산처리
     * Create by tk.kim 2017.09
     *
     * @param $month
     * @param $company_id
     * @return bool
     */
    public function setDrAdjustsForMonth($month, $company_id)
    {
        $ym = Carbon::parse($month)->format('Ym');
        $drs = Dr::select('idx')->where('company_id', $company_id)->get();
        if ($this->getAdjustIsPayed($ym, AdjustDrDetail::class, $drs)) return true;
        AdjustDrDetail::where('ym', $ym)->whereIn('ref_dr_idx', $drs)->delete();

        // 자발적 DR 때문에 2020-01-01 기준으로 정산 방법 달라짐
        if (Carbon::parse(Carbon::parse($month)->format('Y-m-01 00:00:01'))->timestamp >  Carbon::parse('2020-01-01 00:00:00')->timestamp) {
            // 자발적 DR 정산 2020-01-01 기준으로 시행하는
            return $this->setDrAdjustsForMonthByVoluntary($month, $company_id);
        }

        $drs = [];
        $dr = [
            'idx' => 0,
            'name' => '',
            'id' => '',
            'dr_month_reduce_order_qty' => 0,
            'dr_month_reduce_qty' => 0,
            'reduce_adjust_capacity' => 0,
            'reduce_hour' => 0,
        ];

        $select = [
            '_reduce_interval.*',
            '_reduce.reduce_type',
            '_reduce.start_date',
            '_reduce.dr_reduce_adjust_capacity',
            '_dr.idx as dr_idx',
            '_dr.name as dr_name',
            '_dr.id as dr_id',
            '_dr.reduce_hour as dr_reduce_hour',
            'adjust_smp_prices.price as adjust_smp_price',
        ];

        $sr_select = [
            '_reduce_interval.*',
            '_reduce.reduce_type',
            '_reduce.start_date',
            '_reduce.dr_reduce_adjust_capacity',
        ];

        $reduce_intervals = ReduceInterval::select($select)
            ->leftJoin('_reduce', '_reduce_interval.ref_reduce_idx', '_reduce.idx', '_reduce.start_date')
            ->leftJoin('_dr', '_reduce.ref_dr_id', '_dr.id')
            ->leftJoin('adjust_smp_prices', function ($join) {
                $join->on(DB::raw("date_format(from_unixtime(_reduce.start_date), '%Y%m%d')"), '=', "adjust_smp_prices.ymd")
                    ->on(DB::raw("LPAD((_reduce_interval.start_h + 1), 2, '0')"), '=', 'adjust_smp_prices.h');
            })
            ->where('_dr.company_id', $company_id)
            ->whereBetween('_reduce.start_date', [
                Carbon::parse($month)->firstOfMonth()->timestamp,
                Carbon::parse(Carbon::parse($month)->lastOfMonth()->format('Y-m-d 23:59:59'))->timestamp
            ])
            ->whereRaw("? BETWEEN date_format(_dr.start_date, '%Y-%m') AND date_format(_dr.end_date, '%Y-%m')", $month)
            ->whereIn('_reduce.reduce_type', [0, 1, 4, 7, 8])
            ->get();

        $_ym = Carbon::parse($month)->format('Y-m-01 00:00:01');
        $y = Carbon::parse($month)->format('Y');

        foreach ($reduce_intervals as $reduce_interval) {
            $bp = $this->getCacheBPbyYm($ym, $reduce_interval->dr_idx);
            $dr['idx'] = $reduce_interval->dr_idx;
            $dr['name'] = $reduce_interval->dr_name;
            $dr['id'] = $reduce_interval->dr_id;
            $dr['reduce_adjust_capacity'] = $reduce_interval->dr_reduce_adjust_capacity;
            $dr['reduce_hour'] = $reduce_interval->dr_reduce_hour;
            $dr['drd'] = 0;

            $start_date = Carbon::createFromTimestamp($reduce_interval->start_date)->format('Y-m-d H시');
            $ymdH = Carbon::createFromTimestamp($reduce_interval->start_date)->addHour($reduce_interval->no)->format("Y-m-d {$reduce_interval->start_h}:{$reduce_interval->start_m}:00");

            $adjust_calculate_params = [
                'ref_dr_idx' => $reduce_interval->dr_idx,
                'ref_reduce_idx' => $reduce_interval->ref_reduce_idx, 'ref_reduce_no' => $reduce_interval->no, 'type' => 'profit',
                'sr_ref_reduce_idx' => 0, 'sr_ref_reduce_no' => 0,
                'YM' => $ym, 'YMDH' => $ymdH,
                'ORC' => 0, 'RSO' => 0, 'XRSOF' => 0, 'DCBL' => 0, 'ME' => 0,
                'DR' => 0, 'SMP' => 0, 'DF' => 0, 'DRP' => 0, 'SCBL' => 0,
                'SR' => 0, 'PSSR' => 0, 'SLRP' => 0, 'DRRP' => 0, 'XDRESMP' => 0, 'PPCF' => 1,
                'PPC' => 0, 'BP' => 0, 'TDRBP' => 0, 'MRT' => 0, 'BPCF' => 2, 'DRBP' => 0,
            ];

            $reduce_dateYmd = Carbon::createFromTimestamp($reduce_interval->start_date)->format('Y-m-d');
            $reduce_dateH = $reduce_interval->start_h . '~' . $reduce_interval->end_h . '시';
            $adjust_calculate_params['ORC'] = $reduce_interval->dr_reduce_adjust_capacity * 1000;
            $smp = $reduce_interval->adjust_price;
            if ($smp == 0 and !is_null($reduce_interval->adjust_smp_price) or (!is_null($reduce_interval->adjust_smp_price) and $smp != $reduce_interval->adjust_smp_price)) {
                $smp = $reduce_interval->adjust_smp_price;
                $this->updateReduceIntervalAdjustPrice($reduce_interval->idx, $smp);
            }
            $adjust_calculate_params['SMP'] = $smp;
            $wattage = $this->getReduceInfoFromWattageByIntervalNo($reduce_interval->ref_reduce_idx, $reduce_interval->no);
            if (!isset($drs[$reduce_interval->dr_idx])) $drs[$reduce_interval->dr_idx] = $dr;
            $adjust_calculate_params['ME'] = (!is_null($wattage->v)) ? ($wattage->v < 0.1) ? 0 : $wattage->v : 0;

            if ($reduce_interval->reduce_type == 1) {
                # 계획
                $adjust_calculate_params['SR'] = is_null($wattage->reduce_qty) ? 0 : $wattage->reduce_qty;
                $adjust_calculate_params['SCBL'] = is_null($wattage->cbl) ? 0 : $wattage->cbl;
                $adjust_calculate_params['PSSR'] = $reduce_interval->reduce_order_qty;
                $adjust_calculate_params['DRRP'] = is_null($reduce_interval->price) ? 0 : $reduce_interval->price;
                $this->getCalculateProfitAdjust($adjust_calculate_params);
                # maybe :: maybe need check same time has dr be continue this loop;
            } else {
                $drs[$reduce_interval->dr_idx]['dr_month_reduce_qty'] += $wattage->reduce_qty;
                $drs[$reduce_interval->dr_idx]['dr_month_reduce_order_qty'] += $reduce_interval->reduce_order_qty;

                $adjust_calculate_params['DCBL'] = is_null($wattage->cbl) ? 0 : $wattage->cbl;
                $adjust_calculate_params['DR'] = (is_null($wattage->reduce_qty) && $wattage->reduce_qty <= 0) ? 0 : round($wattage->reduce_qty, 3);
                $adjust_calculate_params['RSO'] = (is_null($reduce_interval->reduce_order_qty) && $reduce_interval->reduce_order_qty <= 0) ? 0 : round($reduce_interval->reduce_order_qty, 3);;
                $adjust_calculate_params['XRSOF'] = ($adjust_calculate_params['RSO'] > $adjust_calculate_params['ORC']) ? 1 : 0;
                $adjust_calculate_params['DF'] = 1;
                $adjust_calculate_params['BP'] = $bp;

                try {
                    $adjust_calculate_params['DR'] = ($adjust_calculate_params['DCBL'] != 0) ? round($adjust_calculate_params['DCBL'] - $adjust_calculate_params['ME'], 3) : 0;
                } catch (Exception $e) {
                    $adjust_calculate_params['DR'] = 0;
                }

                $drs[$reduce_interval->dr_idx]['drd'] += max([$adjust_calculate_params['RSO'] * 0.97 - $adjust_calculate_params['DR'], 0]);
                $this->getCalculateProfitAdjust($adjust_calculate_params);
                $has_same_time_reduce_sr = ReduceInterval::select($sr_select)
                    ->leftJoin('_reduce', '_reduce_interval.ref_reduce_idx', '_reduce.idx', '_reduce.start_date')
                    ->whereBetween('_reduce.start_date', [
                        Carbon::parse(Carbon::parse($reduce_dateYmd)->format('Y-m-d 00:00:01'))->timestamp,
                        Carbon::parse(Carbon::parse($reduce_dateYmd)->format('Y-m-d 23:59:59'))->timestamp,
                    ])
                    ->where(['_reduce.reduce_type' => 1, '_reduce.ref_dr_id' => $reduce_interval->dr_id, '_reduce_interval.start_h' => $reduce_interval->start_h])
                    ->first();
                if (!is_null($has_same_time_reduce_sr)) {
                    # 계획
                    $adjust_calculate_params['sr_ref_reduce_idx'] = $has_same_time_reduce_sr->ref_reduce_idx;
                    $adjust_calculate_params['sr_ref_reduce_no'] = $has_same_time_reduce_sr->no;
                    $adjust_calculate_params['SR'] = $has_same_time_reduce_sr->reduce_qty;
                    $adjust_calculate_params['SCBL'] = $has_same_time_reduce_sr->cbl;
                    $adjust_calculate_params['PSSR'] = $has_same_time_reduce_sr->reduce_order_qty;
                    $adjust_calculate_params['DRRP'] = is_null($has_same_time_reduce_sr->adjust_price) ? 0 : $has_same_time_reduce_sr->price;
                    $adjust_calculate_params['DF'] = 0;
                    $this->getCalculateProfitAdjust($adjust_calculate_params);
                    $adjust_calculate_params['DF'] = 1;
                }
            }

            $where = [
                'ref_dr_idx' => $adjust_calculate_params['ref_dr_idx'],
                'ref_reduce_idx' => $adjust_calculate_params['ref_reduce_idx'],
                'ref_reduce_no' => $adjust_calculate_params['ref_reduce_no'],
                'sr_ref_reduce_idx' => $adjust_calculate_params['sr_ref_reduce_idx'],
                'sr_ref_reduce_no' => $adjust_calculate_params['sr_ref_reduce_no'],
                'type' => 'profit'
            ];
            $this->adjustDetailUpdateOrCreate($where, $adjust_calculate_params, AdjustDrDetail::class);
            unset($where, $adjust_calculate_params);
        }

        $drs_original = Dr::where('company_id', $company_id)
            ->whereRaw("? BETWEEN date_format(_dr.start_date, '%Y-%m') AND date_format(_dr.end_date, '%Y-%m')", $month)
            ->get();

        foreach ($drs_original as $item) {
            if (isset($drs[$item->idx])) continue;

            $drs[$item->idx] = [
                'idx' => $item->idx,
                'name' => $item->name,
                'reduce_hour' => $item->reduce_hour,
                'reduce_adjust_capacity' => $item->reduce_adjust_capacity,
                'dr_month_reduce_order_qty' => 0,
                'dr_month_reduce_qty' => 0,
                'drd' => 0
            ];
        }

        foreach ($drs as $dr) {
            $tdr_bp = $this->getCacheDrBPbyDrIdx($dr['idx']);
            $bp = $this->getCacheBPbyYm($ym, $dr['idx']);
            $adjust_calculate_params = [
                'ref_dr_idx' => $dr['idx'],
                'type' => 'default', 'YM' => $ym, 'YMDH' => $_ym,
                'ORC' => 0, 'BP' => 0, 'DRBP' => 0, 'TDRBP' => 0,
                'MRT' => 0, 'RSO' => 0, 'DR' => 0, 'DRD' => 0,
                'BPCF' => 2, 'DF' => 1, 'IBPC' => 0, 'BPC' => 0, 'TDRBP_VALUE' => 0,
            ];

            $adjust_calculate_params['ORC'] = $dr['reduce_adjust_capacity'] * 1000;
            $adjust_calculate_params['BP'] = $bp;
            $adjust_calculate_params['TDRBP_VALUE'] = $tdr_bp;
            $adjust_calculate_params['TDRBP'] = round($adjust_calculate_params['ORC'] * $tdr_bp, 2);
            $adjust_calculate_params['MRT'] = $dr['reduce_hour'];
            $adjust_calculate_params['RSO'] = ($drs[$dr['idx']]['dr_month_reduce_order_qty'] != 0) ? round($drs[$dr['idx']]['dr_month_reduce_order_qty'], 3) : 0;
            $adjust_calculate_params['DR'] = ($drs[$dr['idx']]['dr_month_reduce_qty'] != 0) ? round($drs[$dr['idx']]['dr_month_reduce_qty'], 3) : 0;
            $adjust_calculate_params['DF'] = ($drs[$dr['idx']]['dr_month_reduce_order_qty'] != 0) ? 1 : 0;
            $adjust_calculate_params['DRD'] = $drs[$dr['idx']]['drd'];

            $this->getCalculateDefaultAdjust($adjust_calculate_params);
            if ($adjust_calculate_params['DRBP'] != 0) $adjust_calculate_params['DRBP'] = round($adjust_calculate_params['DRBP']);
            $where = [
                'ref_dr_idx' => $adjust_calculate_params['ref_dr_idx'],
                'YM' => $ym,
                'type' => 'default'
            ];
            $this->adjustDetailUpdateOrCreate($where, $adjust_calculate_params, AdjustDrDetail::class);
            unset($where, $adjust_calculate_params);
        }
        return true;
    }

    /**
     * 자발적 DR 로 인한 정산 방법 변경
     * 2020-03-16
     */

    private function setDrAdjustsForMonthByVoluntary($month = "", $companyId = 0) {

        if (empty($month)) {
            return false;
        }

        if (empty($companyId)) {
            return false;
        }

        // 쿼리 생성하고
        $reduceIntervals = $this->getReduceIntervalOfDr($month, $companyId);

        // 정산할 데이터 없으면 false 해준다.
        if (empty($reduceIntervals)) {
            return false;
        }

        $previousMonth = Carbon::parse($month . '-01')->subMonth()->format('Ym');
        $month = Carbon::parse($month . '-01')->format('Ym');
        $nextMonth = Carbon::parse($month)->addMonth()->format('Ym');

        // 정산월계수
        $DBPF = 0;

        if (in_array(Carbon::parse($month)->format('m'), array('02', '05', '08', '11'))) {
            $DBPF = 1;
        }

        $adjustByDr = [];

        // 자발적 DR 인지 아닌지 체크 하는
        $voluntaryReduce = array(1, 7, 8);

        foreach ($reduceIntervals as $interval) {
            $adjustByDr[$interval->idx] = array(
                'BP' => 0, 'NextBP' => 0, 'ORC' => 0, 'FFSF' => 0, 'DFSF' => 0, 'FBP' => 0, 'NextFBP' => 0, 'DR' => 0, 'DLR' => 0, 'DRP' => 0,
                'ME' => 0, 'DCBL' => 0, 'RSO' => 0, 'DR' => 0, 'DLR' => 0, 'DRP' => 0, 'MRT' => 0, 'DRD' => 0, 'BPCF' => 0,
                'IBPC' => 0, 'FBPCC' => 0, 'IFBPC' => 0, 'FBPC' => 0, 'DBPCC' => 0,
                'IDBPC' => 0, 'DBPC' => 0, 'BPC' => 0, 'DBPC' => 0, 'DBP' => 0, 'DRDBP' => 0, 'DRBP' => 0, 'PSRF' => 0,
                'DSRF' => 0, 'ESRF' => 0, 'PSRF' => 0, 'DEDF' => 0, 'PSSR' => 0, 'PSR' => 0, 'PSLR' => 0, 'PSLRP' => 0, 'PDSR' => 0, 'DESR' => 0, 'DSR' => 0,
                'DLR' => 0, 'DSLR' => 0, 'DSLRP' => 0, 'PESR' => 0, 'EMSR' => 0, 'ESR' => 0, 'ESLR' => 0, 'ESLRP' => 0, 'XDRESMP' => 0, 'SLRP' => 0,
                'PPPC' => 0, 'DPPC' => 0, 'EPPC' => 0, 'PPC' => 0
            );


            $adjustByDr[$interval->idx]['ref_reduce_idx'] = $interval->ref_reduce_idx;
            $adjustByDr[$interval->idx]['ref_reduce_no'] = $interval->no;

            $start_date = Carbon::parse(Carbon::createFromTimestamp($interval->start_date)->format("Y-m-d"))->addHours($interval->start_h)->format("Y-m-d H:i:s");
            $adjustByDr[$interval->idx]['YMDH'] = $start_date;

            // 당월 기본정산금단가
            $adjustByDr[$interval->idx]['BP'] = $this->getCacheBPbyYm($month, $interval->dr_idx);

            // 익월 기본정산금단가
            $adjustByDr[$interval->idx]['NextBP'] = $this->getCacheBPbyYm($nextMonth, $interval->dr_idx);

            $orc = (int)$interval->dr + (int)$interval->test + (int)$interval->request + (int)$interval->peak + (int)$interval->dust;
            // 당월확정 고정 기본정산금
            $adjustByDr[$interval->idx]['ORC'] = $orc;

            // FFSF 당월 고정연료 전환성과계수 =? 2020~-01 / 2020-06
            // TODO ffsf 관리 테이블 필요하다
            $adjustByDr[$interval->idx]['FFSF'] = 1;
            $adjustByDr[$interval->idx]['DFSF'] = 1;

            // ORC * BP * FFSF = 당월확정 고정 기본정산금 (FBP)
            $adjustByDr[$interval->idx]['FBP'] = round($adjustByDr[$interval->idx]['ORC'] * $adjustByDr[$interval->idx]['BP'] * 1, 0);

            // 익월 고정기본 정산금 (FBP)
            $adjustByDr[$interval->idx]['NextFBP'] = round($adjustByDr[$interval->idx]['ORC'] * $adjustByDr[$interval->idx]['NextBP'] * 1, 0);

            // 차등 기본 정산금 ORC * BP * (FFSF - DFSF)
            // TODO FFSF - DFSF 값 넣어줘야하고, 관리 테이블 필요함
            $adjustByDr[$interval->idx]['DBP'] = $adjustByDr[$interval->idx]['ORC'] * $adjustByDr[$interval->idx]['BP'] * ($adjustByDr[$interval->idx]['DFSF'] - $adjustByDr[$interval->idx]['FFSF']);

            // 당월 확정 차등 기본정산금
            $adjustByDr[$interval->idx]['DRDBP'] = ($adjustByDr[$interval->idx]['DBP'] - (isset($DrDBPBynextMonth) ? $DrDBPBynextMonth : 0) ) * $DBPF;

            // 기본 정산금
            $adjustByDr[$interval->idx]['DRBP'] = $adjustByDr[$interval->idx]['FBP'] + $adjustByDr[$interval->idx]['DRDBP'];

            /**
             * 여기까지는 기본 정산금
             */
            $where = [
                'ref_dr_idx'        => $interval->dr_idx,
                'YM'                => $month,
                'YMDH'              => $start_date,
                'type'              => 'default',
                'ref_reduce_idx'    => $interval->ref_reduce_idx,
                'ref_reduce_no'     => $interval->no
            ];

            // 일단 없애기
//            unset($adjustByDr[$interval->idx]['NextBP']);
//            unset($adjustByDr[$interval->idx]['NextFBP']);
//            unset($adjustByDr[$interval->idx]['DBP']);

            $wattage = $this->getReduceFromWattageByDr($interval->ref_reduce_idx, $interval->start_h);

            $adjustByDr[$interval->idx]['DCBL'] = empty($wattage->cbl_dr)? 0 : (int)$wattage->cbl_dr;
            $adjustByDr[$interval->idx]['SCBL'] = empty($wattage->cbl_sr)? 0 : (int) $wattage->cbl_sr;
            $adjustByDr[$interval->idx]['ME'] = empty($wattage->v) ? 0 : $wattage->v;

            $this->adjustDetailUpdateOrCreate($where, $adjustByDr[$interval->idx], AdjustDrDetail::class);

            // 급전감축량, 의무감축요청량
            $cblSr = isset($wattage['cbl_sr']) && !empty($wattage['cbl_sr']) ? $wattage['cbl_sr'] : 0;
            $cblDr = isset($wattage['cbl_dr']) && !empty($wattage['cbl_dr']) ? $wattage['cbl_dr'] : 0;
            $v = isset($wattage['v']) && !empty($wattage['v']) ? $wattage['v'] : 0;

            // 자발적 dr 이랑 포함되어있는지 체크해보기
            $reduceType = explode(",", $interval->reduce_type);

            $adjustByDr[$interval->idx]['DR'] = 0;
            $adjustByDr[$interval->idx]['DLR'] = 0;
            $adjustByDr[$interval->idx]['DRP'] = 0;
            $adjustByDr[$interval->idx]['ME'] = empty($wattage->v) ? 0 : $wattage->v;

            // 자발적 DR (경제성 Dr, 미세먼지, 피크수요)
            // 급전 있는 경우
            if (count(array_intersect(array(0, 4), $reduceType)) > 0) {
                // 감축시험

                // CBL
                $adjustByDr[$interval->idx]['DCBL'] = $cblDr;

                // RSO 의무감축요청량
                $adjustByDr[$interval->idx]['RSO'] = !empty($interval->reduce_order_qty) ? $interval->reduce_order_qty : 0;
                // DR = 급전감축량 (DCBL - 사용량)
                $adjustByDr[$interval->idx]['DR'] = $cblDr - $v;

                // DLR = 급전감축 감축량 인정량 (PSR SCBL - 사용량)
                $adjustByDr[$interval->idx]['DLR'] = $cblDr - $v > 0 ? $cblDr - $v : 0;

                // DRP = 급전감축 실척정산금(DRP)
                $quantity = $interval->dr + $interval->test;
                $adjustByDr[$interval->idx]['DRP'] = min(($cblDr - $v), ($quantity * 1.2)) * $interval->ri_adjust_price;

                /**
                 * 위약금 관련된 부분
                 */

                // TODO 전력거래시간 기본정산금 단가 (DRHCF, kW-m) - 해당자원 합
//                $originPrice = 294.1;
//                $originPrice = 10000;
                $date = Carbon::createFromTimestamp($interval['start_date'])->format('Ymd');
                $priceUnit = ReducePriceUnit::select('price')->where(['ymd' => $date, 'h' => $interval->start_h])->first();
                $originPrice = isset($priceUnit['price']) && !empty($priceUnit['price']) ? $priceUnit['price'] : 0;


                // 최대감축시간 MRT
                $adjustByDr[$interval->idx]['MRT'] = $interval->duration;

                // 전력거래월수 DRTM
                $drtm = 0;
                if ($interval->duration == 60) {
                    $drtm = 12;
                } else if ($interval->duration == 30) {
                    $drtm = 6;
                }

                $adjustByDr[$interval->idx]['DRTM'] = $drtm;

                // DRD 급전감축 미이행량 DRD max(의무감축용량 * 0.97 - (cbl - v), 0)
                $adjustByDr[$interval->idx]['DRD'] = max(($interval->reduce_order_qty * 0.97) - ($cblDr - $v), 0);

                // 급전감축 위약금 계수 2 -> default tptxld
                $adjustByDr[$interval->idx]['BPCF'] = 2;

                // 기본정산금 상한 적용전 기본위약금 (IBPC)
                // 정산금 단가 / (최대감축시간 / 전력거래월수) * 급전감축 미이행량 * 급전감축 위약금 계수
                // DRHCF / (MRT / DRTM) * DRD * DPCF
                $ibpc = $originPrice / ($interval->duration / $drtm) * $adjustByDr[$interval->idx]['DRD'] * $adjustByDr[$interval->idx]['BPCF'];

                $adjustByDr[$interval->idx]['IBPC'] = $ibpc;

                $previousByDefault = AdjustDrDetail::where(['YM' => $previousMonth, 'ref_dr_idx' => $interval->dr_idx, 'type' => 'default'])->get();

                $previousMonthIBPC = isset($previousByDefault['IBPC']) && $previousByDefault['IBPC'] > 0 ? $previousByDefault['IBPC'] : 0;
                $previousMonthIFBPC = isset($previousByDefault['IFBPC']) && $previousByDefault['IFBPC'] > 0 ? $previousByDefault['IFBPC'] : 0;;

                // 전월에서 이월된 고정기본정산금 한도의 기본위약금 (FBPCC)
                $adjustByDr[$interval->idx]['FBPCC'] = min(($previousMonthIBPC - $previousMonthIFBPC), $adjustByDr[$interval->idx]['FBP']);

                // 고정기본정산금 한도의 기본 위약금 IFBPC
                $adjustByDr[$interval->idx]['IFBPC'] = min($ibpc, $adjustByDr[$interval->idx]['FBP'] - $adjustByDr[$interval->idx]['FBPCC']);

                // 고정기본정산금 위약금 한도 FBPC
                // FBPCC + IFBPC
                $adjustByDr[$interval->idx]['FBPC'] = $adjustByDr[$interval->idx]['FBPCC'] + $adjustByDr[$interval->idx]['IFBPC'];

                // 고정기본정산금 한고의 기본위약금 (익월 FBPCC)
                // min(IBPC - IFBPC, NextFBP)
                $adjustByDr[$interval->idx]['nextFBPCC'] = min( ($ibpc -$adjustByDr[$interval->idx]['IFBPC']) ,$adjustByDr[$interval->idx]['NextFBP']);

                $adjustByDr[$interval->idx]['RBPC'] = $adjustByDr[$interval->idx]['IBPC'] - $adjustByDr[$interval->idx]['IFBPC'] - $adjustByDr[$interval->idx]['nextFBPCC'];

                // DBPCC
                // min(previousRBPC - IDBPC, DBP)
                // DBP = 차등기본정산금

                // 전월 rbpc
                $adjustByDr[$interval->idx]['previousRBPC'] = 0;
                $adjustByDr[$interval->idx]['previousIDBPC'] = 0;
                $adjustByDr[$interval->idx]['DBPCC'] = min(($adjustByDr[$interval->idx]['previousRBPC'] - $adjustByDr[$interval->idx]['previousIDBPC']), $adjustByDr[$interval->idx]['DBP']);
                $adjustByDr[$interval->idx]['IDBPC'] = min($adjustByDr[$interval->idx]['RBPC'], ($adjustByDr[$interval->idx]['DBP'] - $adjustByDr[$interval->idx]['DBPCC']));

                $adjustByDr[$interval->idx]['nextDBPC'] = 0;

                // 정산금 위약금 한도
                $adjustByDr[$interval->idx]['DBPC'] = ($adjustByDr[$interval->idx]['DBPCC'] + $adjustByDr[$interval->idx]['IDBPC'] - $adjustByDr[$interval->idx]['nextDBPC']) * $DBPF;
                $adjustByDr[$interval->idx]['BPC'] = $adjustByDr[$interval->idx]['FBPC'] + $adjustByDr[$interval->idx]['DBPC'];

                // 차등기본 정산금 위약금 한도 (DBPC)
                $adjustByDr[$interval->idx]['DBPC'] = $adjustByDr[$interval->idx]['IBPC'] - $adjustByDr[$interval->idx]['IFBPC'] - $adjustByDr[$interval->idx]['nextFBPCC'];

                // 급전감축 위약금 BPC
                // 위약금 한도(FBPC) + DBPC
                $adjustByDr[$interval->idx]['BPC'] = $adjustByDr[$interval->idx]['FBPC'] + $adjustByDr[$interval->idx]['DBPC'];
                unset($adjustByDr[$interval->idx]['DRTM']);
            }

            echo '-------------------------------';

            echo "<pre>";
            print_r($adjustByDr);
            echo "</pre>";
            exit;
            // 자발적 DR 인경우
            if (count(array_intersect($voluntaryReduce, $reduceType)) > 0) {

                // group by reduce_type 해놓고,
                // 1, 7, 8 등등 있으면 true 배열로 넣어놓고 값
                if (in_array(1, $reduceType)) {
                    $adjustByDr[$interval->idx]['PSRF'] = (int) $interval->request > 0 ? true : false;

                    // 피크수요 참여 여부
                    $adjustByDr[$interval->idx]['DSRF'] = (int) $interval->peak > 0 ? true : false;

                    // 미세먼지 참여여부
                    $adjustByDr[$interval->idx]['ESRF'] = (int) $interval->dust > 0 ? true : false;

                    $dedf = false;

                    if ($adjustByDr[$interval->idx]['PSRF'] && $adjustByDr[$interval->idx]['DSRF'] && $adjustByDr[$interval->idx]['ESRF']) {
                        $dedf = true;
                    }

                    // 경제성 DR 참여한 고객이 피크수요 OR 미세먼지에 참여여부
                    $adjustByDr[$interval->idx]['DEDF'] = $dedf;

                    $adjustByDr[$interval->idx]['PSSR'] = (int) $interval->request > 0 ? $interval->request : 0;

                    $adjustByDr[$interval->idx]['PSR'] = $cblSr - $v;

                    // min(max(경제성dr 감축인정량 - 급전감축량, 0), 경제성 dr 감축계획량)
                    $adjustByDr[$interval->idx]['PSLR'] = min(max($cblSr - $v, 0), (int)$interval->request);

                    // smp * 감축 인정량 ** SMP ==>adjust_price
                    // 경제성 DR 실적 정산금 (PSLRP)
                    $adjustByDr[$interval->idx]['PSLRP'] = round($adjustByDr[$interval->idx]['PSLR'] * $interval->ri_adjust_price, 1);
                }

                // 피크수요 있는지 체크
                if (in_array(7, $reduceType)) {
                    // 피크수요
                    // 감축계획

                    // 동시참여 감축량
                    $adjustByDr[$interval->idx]['PDSR'] = $cblSr - $v;

                    $adjustByDr[$interval->idx]['DESR'] = $interval->peak;

                    // 피크수요 감축량getDrAdjustsForMonth
                    $adjustByDr[$interval->idx]['DSR'] = ($cblSr - $v) * (int)$adjustByDr[$interval->idx]['DSRF'] * (1 - (int)$adjustByDr[$interval->idx]['PSRF']);

                    // 경제성 피크수요 DR 미세먼지 동시참여자 감축량
                    $dlr = isset($adjustByDr[$interval->idx]['DLR']) ? $adjustByDr[$interval->idx]['DLR'] : 0;

                    // 피크수요 DR 감축인정량 (DSLR)
                    $adjustByDr[$interval->idx]['DSLR'] = min(max(($adjustByDr[$interval->idx]['PDSR'] - $interval->request) * 1 + $adjustByDr[$interval->idx]['DSR'] - $dlr, 0), $interval->peak);
                    $adjustByDr[$interval->idx]['DSLRP'] = round($adjustByDr[$interval->idx]['DSLR'] * $interval->ri_adjust_price, 1);
                }

                // 미세먼지 체크
                if (in_array(8, $reduceType)) {
                    // 피크수요

                    $adjustByDr[$interval->idx]['PESR'] = ($cblSr - $v) * (int) $adjustByDr[$interval->idx]['PSRF'] * (int) $adjustByDr[$interval->idx]['ESRF'];
                    $adjustByDr[$interval->idx]['EMSR'] = $interval->dust;


                    // 미세먼지 감축량
                    $adjustByDr[$interval->idx]['ESR'] = ($cblSr - $v) * (int) $adjustByDr[$interval->idx]['ESRF'] * (1 - (int) $adjustByDr[$interval->idx]['PSRF']);

                    $eslr = min(max(($adjustByDr[$interval->idx]['PESR'] - $adjustByDr[$interval->idx]['PSLR']) * (int) $adjustByDr[$interval->idx]['DEDF'] + $adjustByDr[$interval->idx]['ESR'] - $adjustByDr[$interval->idx]['DSLR'], 0), $interval->dust);
                    $adjustByDr[$interval->idx]['ESLR'] = $eslr;
                    $adjustByDr[$interval->idx]['ESLRP'] = $eslr * $interval->ri_adjust_price;
                }

                $xdresmp = max($interval->ri_price * min(max((int)$adjustByDr[$interval->idx]['PSR'] - (int)$adjustByDr[$interval->idx]['DSR'], 0), $adjustByDr[$interval->idx]['PSSR']) * 1000 - $adjustByDr[$interval->idx]['PSLRP'], 0);
                $adjustByDr[$interval->idx]['XDRESMP'] = $xdresmp;
                $adjustByDr[$interval->idx]['SLRP'] = $adjustByDr[$interval->idx]['PSLRP'] + $adjustByDr[$interval->idx]['DSLRP'] + $adjustByDr[$interval->idx]['ESLRP'] + $adjustByDr[$interval->idx]['XDRESMP'];

                /**
                 * 자발적 DR 위약금
                 */

                $adjustByDr[$interval->idx]['PPPC'] = ($adjustByDr[$interval->idx]['PSSR'] - $adjustByDr[$interval->idx]['PSLR']) * $interval->ri_adjust_price;
                $adjustByDr[$interval->idx]['DPPC'] = ($adjustByDr[$interval->idx]['DESR'] - $adjustByDr[$interval->idx]['DSLR']) * $interval->ri_adjust_price;
                $adjustByDr[$interval->idx]['EPPC'] = ($adjustByDr[$interval->idx]['EMSR'] - $adjustByDr[$interval->idx]['ESLR']) * $interval->ri_adjust_price;
                $adjustByDr[$interval->idx]['PPC'] = $adjustByDr[$interval->idx]['PPPC'] + $adjustByDr[$interval->idx]['DPPC'] + $adjustByDr[$interval->idx]['EPPC'];
            }

            $where = [
                'ref_dr_idx'        => $interval->dr_idx,
                'YM'                => $month,
                'YMDH'              => $start_date,
                'type'              => 'profit',
                'ref_reduce_idx'    => $interval->ref_reduce_idx,
                'ref_reduce_no'     => $interval->no
            ];

            $this->adjustDetailUpdateOrCreate($where, $adjustByDr[$interval->idx], AdjustDrDetail::class);

        }
    }

    /**
     * 참여고객 월별 정산처리
     * Create by tk.kim 2017.08
     *
     * @param $month
     * @param $company_id
     * @return bool
     */
    public function setContractAdjustsForMonth($month, $company_id = 1)
    {
        $ym = Carbon::parse($month)->format('Ym');
        $company_id = session('admin_login_info.company_id', $company_id);
        $drs = Dr::select('idx')->where('company_id', $company_id)->get();
        if ($this->getAdjustIsPayed($ym, AdjustContractDetail::class, $drs)) {
            return true;
        }
        AdjustContractDetail::where('ym', $ym)->whereIn('ref_dr_idx', $drs)->delete();
        AdjustEtcDetail::where('ym', $ym)->whereIn('ref_dr_idx', $drs)->delete();

        // 자발적 DR 때문에 2020-01-01 기준으로 정산 방법 달라짐
        if (Carbon::parse(Carbon::parse($month)->format('Y-m-01 00:00:01'))->timestamp >  Carbon::parse('2020-01-01 00:00:00')->timestamp) {
            // 자발적 DR 정산 2020-01-01 기준으로 시행하는
            return $this->setContractAdjustsForMonthByVoluntary($month, $company_id);
        }

        $reduce_contract_intervals_select = [
            '_reduce_contract_interval.*',
            '_reduce_interval.no AS ri_no',
            '_reduce_interval.price AS ri_price',
            '_reduce_interval.adjust_price AS ri_adjust_price',
            '_reduce_interval.MGP AS mgp',
            '_reduce.start_date',
            '_reduce.reduce_type',
            '_dr.idx as dr_idx',
            '_dr.id as dr_id',
            '_dr.reduce_hour as dr_reduce_hour',
            '_contract.idx as contract_idx',
            '_contract.name as contract_name',
            '_contract_capacity.capacity as contract_capacity',
            'adjust_default_rates.idx as adjust_idx',
            'adjust_default_rates.d_default',
            'adjust_default_rates.d_profit',
            'adjust_default_rates.d_break',
            'adjust_default_rates.s_profit',
            'adjust_default_rates.s_over_profit',
            'adjust_default_rates.s_break',
            'adjust_smp_prices.price as adjust_smp_price',
        ];

        $reduce_contract_intervals_sr_select = [
            '_reduce_contract_interval.ref_reduce_idx',
            '_reduce_contract_interval.no',
            '_reduce_contract_interval.ref_contract_idx',
            '_reduce_interval.price',
            'adjust_default_rates.s_profit',
            'adjust_default_rates.s_over_profit',
            'adjust_default_rates.s_break',
        ];

        $reduce_contract_intervals = ReduceContractInterval::select($reduce_contract_intervals_select)
            ->leftJoin('_contract', '_reduce_contract_interval.ref_contract_idx', '_contract.idx')
            ->leftJoin('_reduce', '_reduce_contract_interval.ref_reduce_idx', '_reduce.idx')
            ->leftJoin('_dr', '_reduce.ref_dr_id', '_dr.id')
            ->leftJoin('_reduce_interval', function ($join) {
                $join->on('_reduce_interval.ref_reduce_idx', '=', '_reduce_contract_interval.ref_reduce_idx')
                    ->on('_reduce_interval.start_h', '=', '_reduce_contract_interval.start_h')
                    ->on('_reduce_interval.start_m', '=', '_reduce_contract_interval.start_m');
            })
            ->leftJoin('_contract_capacity', function ($join) use ($ym) {
                $join->on('_contract.idx', '=', '_contract_capacity.ref_contract_idx')->where('ym', $ym);
            })
            ->leftJoin('adjust_default_rates', function ($join) {
                $join->on('_contract.idx', '=', 'adjust_default_rates.c_idx')
                    ->where('type', 'Contract')
                    ->whereRaw('FROM_UNIXTIME(_reduce.start_date) BETWEEN adjust_default_rates.start_date AND adjust_default_rates.end_date');
            })
            ->leftJoin('adjust_smp_prices', function ($join) {
                $join->on(DB::raw("date_format(from_unixtime(_reduce.start_date), '%Y%m%d')"), '=', "adjust_smp_prices.ymd")
                    ->on(DB::raw("LPAD((_reduce_interval.start_h + 1), 2, '0')"), '=', 'adjust_smp_prices.h');
            })
            ->whereNotNull('_reduce.idx')
            ->where('_contract.company_id', $company_id)
            ->whereBetween('_reduce.start_date', [
                Carbon::parse($month)->firstOfMonth()->timestamp,
                Carbon::parse(Carbon::parse($month)->lastOfMonth()->format('Y-m-d 23:59:59'))->timestamp
            ])
            ->whereIn('_reduce.reduce_type', [0, 1, 4, 7, 8])
            ->whereRaw("? BETWEEN date_format(_dr.start_date, '%Y-%m') AND date_format(_dr.end_date, '%Y-%m')", $month)
            ->get();

        $ym = Carbon::parse($month)->format('Ym');
        $_ym = Carbon::parse($month)->format('Y-m-01 00:00:01');
        $y = Carbon::parse($month)->format('Y');

        // 자발적 DR 때문에 2020-01-01 기준으로 정산 방법 달라짐
        if (Carbon::parse(Carbon::parse($month)->format('Y-m-01 00:00:01'))->timestamp >  Carbon::parse('2020-01-01 00:00:00')->timestamp) {
            // 자발적 DR 정산 2020-01-01 기준으로 시행하는
            return $this->setContractAdjustsForMonthByVoluntary($reduce_contract_intervals, $month, $company_id);
        }
        $contracts = [];
        $adjust_default_rate_null_contracts = [];
        foreach ($reduce_contract_intervals as $reduce_contract_interval) {
            if (!is_null($reduce_contract_interval->adjust_idx)) continue;
            $adjust_default_rate_null_contracts[$reduce_contract_interval->ref_contract_idx] = $reduce_contract_interval->contract_name;
        }
//        if (!empty($adjust_default_rate_null_contracts)) return ['code' => 400, 'message' => '참여고객 [' . implode('], [', array_values($adjust_default_rate_null_contracts)) . '] 은 해당 월에 지급율이 없으므로 정산을 정상적으로 진행 하지 못했습니다. 먼저 지급율을 등록후 다시 시도 하십시오. '];

        foreach ($reduce_contract_intervals as $reduce_contract_interval) {
            if (is_null($reduce_contract_interval->adjust_idx)) continue;
            $bp = $this->getCacheBPbyYm($ym, $reduce_contract_interval->dr_idx);
            $start_date = Carbon::createFromTimestamp($reduce_contract_interval->start_date)->format('Y-m-d H시');
            $ymdH = Carbon::createFromTimestamp($reduce_contract_interval->start_date)->addHour($reduce_contract_interval->ri_no)->format("Y-m-d {$reduce_contract_interval->start_h}:{$reduce_contract_interval->start_m}:00");

            $adjust_calculate_params = [
                'ref_contract_idx' => $reduce_contract_interval->contract_idx, 'ref_dr_idx' => $reduce_contract_interval->dr_idx,
                'ref_reduce_idx' => $reduce_contract_interval->ref_reduce_idx, 'ref_reduce_no' => $reduce_contract_interval->no, 'type' => 'profit',
                'sr_ref_reduce_idx' => 0, 'sr_ref_reduce_no' => 0,
                'YM' => $ym, 'YMDH' => $ymdH,
                'ORC' => 0, 'RSO' => 0, 'XRSOF' => 0, 'DCBL' => 0, 'ME' => 0,
                'DR' => 0, 'SMP' => 0, 'DF' => 0, 'DRP' => 0, 'SCBL' => 0,
                'SR' => 0, 'PSSR' => 0, 'SLRP' => 0, 'DRRP' => 0, 'XDRESMP' => 0, 'PPCF' => 1,
                'PPC' => 0, 'BP' => 0, 'TDRBP' => 0, 'MRT' => 0, 'BPCF' => 2, 'DRBP' => 0,
                'PAY_DPT' => 0, 'PAY_DPP' => 0, 'PAY_SPT' => 0, 'PAY_SPP' => 0, 'PAY_SOPT' => 0, 'PAY_SOPP' => 0, 'PAY_SBT' => 0, 'PAY_SBP' => 0,
                'PAY_DDT' => 0, 'PAY_DDP' => 0, 'PAY_DBT' => 0, 'PAY_DBP' => 0,
            ];
            $contract = [
                'idx' => $reduce_contract_interval->contract_idx,
                'dr_idx' => $reduce_contract_interval->dr_idx,
                'name' => $reduce_contract_interval->contract_name,
                'capacity' => $reduce_contract_interval->contract_capacity,
                'dr_month_reduce_qty' => 0,
                'dr_month_reduce_order_qty' => 0,
                'reduce_hour' => $reduce_contract_interval->dr_reduce_hour,
                'dr_default' => $reduce_contract_interval->d_default,
                'dr_break' => $reduce_contract_interval->d_break,
                'drd' => 0,
            ];

            if (!isset($contracts[$reduce_contract_interval->dr_idx][$reduce_contract_interval->contract_idx])) $contracts[$reduce_contract_interval->dr_idx][$reduce_contract_interval->contract_idx] = $contract;
            $reduce_dateYmd = Carbon::createFromTimestamp($reduce_contract_interval->start_date)->format('Y-m-d');
            $reduce_dateH = $reduce_contract_interval->start_h . '~' . $reduce_contract_interval->end_h . '시';
            $adjust_calculate_params['ORC'] = $reduce_contract_interval->contract_capacity;
            $adjust_calculate_params['SMP'] = $reduce_contract_interval->ri_adjust_price == 0 ? (is_null($reduce_contract_interval->adjust_smp_price) ? 0 : $reduce_contract_interval->adjust_smp_price) : $reduce_contract_interval->ri_adjust_price;
            $wattage = $this->getReduceInfoFromWattageByContractIdxAndNo($reduce_contract_interval->ref_reduce_idx, $reduce_contract_interval->contract_idx, $reduce_contract_interval->no);
            $adjust_calculate_params['ME'] = (!is_null($wattage->v)) ? ($wattage->v < 0.1) ? 0 : $wattage->v : 0;

            if ($reduce_contract_interval->reduce_type == 1) {
                # 계획
                $adjust_calculate_params['SR'] = is_null($wattage->reduce_qty) ? 0 : $wattage->reduce_qty;
                $adjust_calculate_params['SCBL'] = is_null($wattage->cbl) ? 0 : $wattage->cbl;
                $adjust_calculate_params['PSSR'] = $reduce_contract_interval->reduce_order_qty;
                $adjust_calculate_params['DRRP'] = is_null($reduce_contract_interval->ri_price) ? 0 : $reduce_contract_interval->ri_price;
                $adjust_calculate_params['PAY_SPT'] = $reduce_contract_interval->s_profit;
                $adjust_calculate_params['PAY_SOPT'] = $reduce_contract_interval->s_over_profit;
                $adjust_calculate_params['PAY_SBT'] = $reduce_contract_interval->s_break;
                $this->getCalculateProfitAdjust($adjust_calculate_params);
                # maybe :: maybe need check same time has dr be continue this loop;
            } else {
                $contracts[$reduce_contract_interval->dr_idx][$reduce_contract_interval->contract_idx]['dr_month_reduce_qty'] += $wattage->reduce_qty;
                $contracts[$reduce_contract_interval->dr_idx][$reduce_contract_interval->contract_idx]['dr_month_reduce_order_qty'] += $reduce_contract_interval->reduce_order_qty;
                $adjust_calculate_params['DCBL'] = is_null($wattage->cbl) ? 0 : $wattage->cbl;
                $adjust_calculate_params['DR'] = (is_null($wattage->reduce_qty) && $wattage->reduce_qty <= 0) ? 0 : round($wattage->reduce_qty, 3);
                $adjust_calculate_params['RSO'] = ($reduce_contract_interval->reduce_order_qty != 0) ? round($reduce_contract_interval->reduce_order_qty, 3) : 0;
                $adjust_calculate_params['XRSOF'] = ($adjust_calculate_params['RSO'] > $adjust_calculate_params['ORC']) ? 1 : 0;
                $adjust_calculate_params['DF'] = 1;
                $adjust_calculate_params['BP'] = $bp;

                try {
                    $adjust_calculate_params['DR'] = ($adjust_calculate_params['DCBL'] != 0) ? round($adjust_calculate_params['DCBL'] - $adjust_calculate_params['ME'], 3) : 0;
                } catch (Exception $e) {
                    $adjust_calculate_params['DR'] = 0;
                }

                $adjust_calculate_params['PAY_DDT'] = $reduce_contract_interval->d_default;
                $adjust_calculate_params['PAY_DPT'] = $reduce_contract_interval->d_profit;
                $contracts[$reduce_contract_interval->dr_idx][$reduce_contract_interval->contract_idx]['drd'] += max([$adjust_calculate_params['RSO'] * 0.97 - $adjust_calculate_params['DR'], 0]);
                $this->getCalculateProfitAdjust($adjust_calculate_params);
                $has_same_time_reduce_sr = ReduceContractInterval::select($reduce_contract_intervals_sr_select)
                    ->leftJoin('_contract', '_reduce_contract_interval.ref_contract_idx', '_contract.idx')
                    ->leftJoin('_reduce', '_reduce_contract_interval.ref_reduce_idx', '_reduce.idx')
                    ->leftJoin('_dr', '_reduce.ref_dr_id', '_dr.id')
                    ->leftJoin('_reduce_interval', function ($join) {
                        $join->on('_reduce_interval.ref_reduce_idx', '=', '_reduce_contract_interval.ref_reduce_idx')
                            ->on('_reduce_interval.start_h', '=', '_reduce_contract_interval.start_h')
                            ->on('_reduce_interval.start_m', '=', '_reduce_contract_interval.start_m');
                    })
                    ->leftJoin('adjust_default_rates', function ($join) {
                        $join->on('_contract.idx', '=', 'adjust_default_rates.c_idx')
                            ->where('type', 'Contract')
                            ->whereRaw('FROM_UNIXTIME(_reduce.start_date) BETWEEN adjust_default_rates.start_date AND adjust_default_rates.end_date');
                    })
                    ->leftJoin('_contract_capacity', function ($join) use ($ym) {
                        $join->on('_contract.idx', '=', '_contract_capacity.ref_contract_idx')->where('ym', $ym);
                    })
                    ->whereNotNull('_reduce.idx')
                    ->whereBetween('_reduce.start_date', [
                        Carbon::parse(Carbon::parse($reduce_dateYmd)->format('Y-m-d 00:00:01'))->timestamp,
                        Carbon::parse(Carbon::parse($reduce_dateYmd)->format('Y-m-d 23:59:59'))->timestamp,
                    ])
                    ->where(['_reduce.reduce_type' => 1, '_reduce.ref_dr_id' => $reduce_contract_interval->dr_id, '_reduce_contract_interval.start_h' => $reduce_contract_interval->start_h])
                    ->first();
                if (!is_null($has_same_time_reduce_sr)) {
                    # 계획
                    $adjust_calculate_params['sr_ref_reduce_idx'] = $has_same_time_reduce_sr->ref_reduce_idx;
                    $adjust_calculate_params['sr_ref_reduce_no'] = $has_same_time_reduce_sr->no;
                    $adjust_calculate_params['SR'] = $has_same_time_reduce_sr->reduce_qty;
                    $adjust_calculate_params['SCBL'] = $has_same_time_reduce_sr->cbl;
                    $adjust_calculate_params['PSSR'] = $has_same_time_reduce_sr->reduce_order_qty;
                    $adjust_calculate_params['DRRP'] = is_null($has_same_time_reduce_sr->price) ? 0 : $has_same_time_reduce_sr->price;
                    $adjust_calculate_params['PAY_SPT'] = $reduce_contract_interval->s_profit;
                    $adjust_calculate_params['PAY_SOPT'] = $reduce_contract_interval->s_over_profit;
                    $adjust_calculate_params['PAY_SBT'] = $reduce_contract_interval->s_break;
                    $adjust_calculate_params['DF'] = 0;
                    $this->getCalculateProfitAdjust($adjust_calculate_params);
                    $adjust_calculate_params['DF'] = 1;
                }
            }

            $where = [
                'ref_contract_idx' => $adjust_calculate_params['ref_contract_idx'],
                'ref_reduce_idx' => $adjust_calculate_params['ref_reduce_idx'],
                'ref_reduce_no' => $adjust_calculate_params['ref_reduce_no'],
                'sr_ref_reduce_idx' => $adjust_calculate_params['sr_ref_reduce_idx'],
                'sr_ref_reduce_no' => $adjust_calculate_params['sr_ref_reduce_no'],
                'type' => 'profit'
            ];
            # 참여고객에만 정수로 처리
            if ($adjust_calculate_params['PAY_SBP'] != 0) $adjust_calculate_params['PAY_SBP'] = round($adjust_calculate_params['PAY_SBP']);
            if ($adjust_calculate_params['PAY_DBP'] != 0) $adjust_calculate_params['PAY_DBP'] = round($adjust_calculate_params['PAY_DBP']);
            if ($adjust_calculate_params['PAY_DPP'] != 0) $adjust_calculate_params['PAY_DPP'] = round($adjust_calculate_params['PAY_DPP']);
            if ($adjust_calculate_params['PAY_SPP'] != 0) $adjust_calculate_params['PAY_SPP'] = round($adjust_calculate_params['PAY_SPP']);
            if ($adjust_calculate_params['PAY_SOPP'] != 0) $adjust_calculate_params['PAY_SOPP'] = round($adjust_calculate_params['PAY_SOPP']);
            $this->adjustDetailUpdateOrCreate($where, $adjust_calculate_params, AdjustContractDetail::class);
            unset($where, $adjust_calculate_params);
        }

        $select = "
            _contract.idx,
            _contract.name,
            CASE WHEN _contract_capacity.capacity IS NULL THEN 0 ELSE _contract_capacity.capacity END AS capacity,
            _dr.idx as dr_idx,
            _dr.start_date,
            _dr.end_date,
            _dr.reduce_hour as dr_reduce_hour,
            CASE WHEN adjust_default_rates.d_default IS NULL THEN 0 ELSE adjust_default_rates.d_default END AS d_default,
            CASE WHEN adjust_default_rates.d_break IS NULL THEN 0 ELSE adjust_default_rates.d_break END AS d_break
        ";
        $contracts_original = Contract::selectRaw($select)
            ->leftJoin('_contract_capacity', function ($join) use ($ym) {
                $join->on('_contract.idx', '=', '_contract_capacity.ref_contract_idx')->where('ym', $ym);
            })
            ->leftJoin('_dr', '_contract_capacity.ref_dr_idx', '_dr.idx')
            ->leftJoin('adjust_default_rates', function ($join) {
                $join->on('_contract.idx', '=', 'adjust_default_rates.c_idx')
                    ->where('type', 'Contract')
                    ->whereRaw("_dr.end_date BETWEEN adjust_default_rates.start_date AND adjust_default_rates.end_date");
            })
            ->whereRaw("? BETWEEN date_format(_dr.start_date, '%Y-%m') AND date_format(_dr.end_date, '%Y-%m')", $month)
            ->where('_contract.company_id', $company_id)
            ->get();
        foreach ($contracts_original as $item) {
            if (isset($contracts[$item->dr_idx][$item->idx])) continue;
            if (is_null($item->dr_idx)) continue;

            $contracts[$item->dr_idx][$item->idx] = [
                'idx' => $item->idx,
                'dr_idx' => $item->dr_idx,
                'name' => $item->name,
                'capacity' => $item->capacity,
                'dr_month_reduce_qty' => 0,
                'dr_month_reduce_order_qty' => 0,
                'reduce_hour' => $item->dr_reduce_hour,
                'dr_default' => $item->d_default,
                'dr_break' => $item->d_break,
                'drd' => 0
            ];
        }

        foreach ($contracts as $drs) {
            foreach ($drs as $contract) {
                $tdr_bp = $this->getCacheDrBPbyDrIdx($contract['dr_idx']);
                $bp = $this->getCacheBPbyYm($ym, $contract['dr_idx']);

                $adjust_calculate_params = [
                    'ref_contract_idx' => $contract['idx'], 'ref_dr_idx' => $contract['dr_idx'],
                    'type' => 'default', 'YM' => $ym, 'YMDH' => $_ym,
                    'ORC' => 0, 'BP' => 0, 'DRBP' => 0, 'TDRBP' => 0,
                    'MRT' => 0, 'RSO' => 0, 'DR' => 0, 'DRD' => 0,
                    'BPCF' => 2, 'DF' => 1, 'IBPC' => 0, 'BPC' => 0, 'TDRBP_VALUE' => 0,
                ];

                $adjust_calculate_params['ORC'] = $contract['capacity'];
                $adjust_calculate_params['BP'] = $bp;
                $adjust_calculate_params['TDRBP_VALUE'] = $tdr_bp;
                $adjust_calculate_params['TDRBP'] = round($adjust_calculate_params['ORC'] * $tdr_bp, 2);
                $adjust_calculate_params['MRT'] = $contract['reduce_hour'];
                $adjust_calculate_params['RSO'] = ($contract['dr_month_reduce_order_qty'] != 0) ? round($contract['dr_month_reduce_order_qty'], 3) : 0;
                $adjust_calculate_params['DR'] = ($contract['dr_month_reduce_qty'] != 0) ? round($contract['dr_month_reduce_qty'], 3) : 0;
                $adjust_calculate_params['DF'] = ($contract['dr_month_reduce_order_qty'] != 0) ? 1 : 0;
                $adjust_calculate_params['PAY_DDT'] = $contract['dr_default'];
                $adjust_calculate_params['PAY_DBT'] = $contract['dr_break'];
                $adjust_calculate_params['DRD'] = $contract['drd'];

                $this->getCalculateDefaultAdjust($adjust_calculate_params);
                # 참여고객에만 정수로 처리
                if (isset($adjust_calculate_params['PAY_DDP']) && ($adjust_calculate_params['PAY_DDP'] != 0)) $adjust_calculate_params['PAY_DDP'] = round($adjust_calculate_params['PAY_DDP']);
                if (isset($adjust_calculate_params['PAY_DBP']) && ($adjust_calculate_params['PAY_DBP'] != 0)) $adjust_calculate_params['PAY_DBP'] = round($adjust_calculate_params['PAY_DBP']);
                $where = [
                    'ref_dr_idx' => $adjust_calculate_params['ref_dr_idx'],
                    'ref_contract_idx' => $adjust_calculate_params['ref_contract_idx'],
                    'YM' => $ym,
                    'type' => 'default'
                ];
                $this->adjustEtcDetailUpdateOrCreate($where);
                $this->adjustDetailUpdateOrCreate($where, $adjust_calculate_params, AdjustContractDetail::class);
                unset($where, $adjust_calculate_params);
            }
        }
        return true;
    }

    /***
     * 자발적 DR 로 인해서 참여고객 별 정산 방법 변경
     * 기본 정산금
     */

    private function setContractAdjustsForMonthByVoluntary($month = "", $company_id = 1)
    {
        if (empty($month)) {
            return false;
        }

        if (empty($company_id)) {
            return false;
        }

        $reduceContractIntervals = $this->getReduceIntervalOfContract($month, $company_id);

        // 정산할 데이터가 없는경우
        if (empty($reduceContractIntervals)) {
            return false;
        }

        $month = Carbon::parse($month . '-01')->format('Ym');
        $previousMonth = Carbon::parse($month)->subMonth()->format('Ym');
        $nextMonth = Carbon::parse($month)->addMonth()->format('Ym');

        // 정산월계수
        $DBPF = 0;

        if (in_array(Carbon::parse($month)->format('m'), array('02', '05', '08', '11'))) {
            $DBPF = 1;
        }

        $adjustByContract = [];

        // 자발적 DR 인지 아닌지 체크 하는
        $voluntaryReduce = array(1, 7, 8);

        /** @var
         * 기본 정산금
         */
        foreach ($reduceContractIntervals as $interval) {

            $adjustByContract[$interval->idx] = array(
                'BP' => 0, 'NextBP' => 0, 'ORC' => 0, 'FFSF' => 0, 'DFSF' => 0, 'FBP' => 0, 'NextFBP' => 0, 'DR' => 0, 'DLR' => 0, 'DRP' => 0,
                'ME' => 0, 'DCBL' => 0, 'RSO' => 0, 'DR' => 0, 'DLR' => 0, 'DRP' => 0, 'MRT' => 0, 'DRD' => 0, 'BPCF' => 0,
                'IBPC' => 0, 'FBPCC' => 0, 'IFBPC' => 0, 'FBPC' => 0, 'DBPCC' => 0,
                'IDBPC' => 0, 'DBPC' => 0, 'BPC' => 0, 'DBPC' => 0, 'DBP' => 0, 'DRDBP' => 0, 'DRBP' => 0, 'PSRF' => 0,
                'DSRF' => 0, 'ESRF' => 0, 'PSRF' => 0, 'DEDF' => 0, 'PSSR' => 0, 'PSR' => 0, 'PSLR' => 0, 'PSLRP' => 0, 'PDSR' => 0, 'DESR' => 0, 'DSR' => 0,
                'DLR' => 0, 'DSLR' => 0, 'DSLRP' => 0, 'PESR' => 0, 'EMSR' => 0, 'ESR' => 0, 'ESLR' => 0, 'ESLRP' => 0, 'XDRESMP' => 0, 'SLRP' => 0,
                'PPPC' => 0, 'DPPC' => 0, 'EPPC' => 0, 'PPC' => 0
            );

            $adjustByContract[$interval->idx]['ref_reduce_idx'] = $interval->ref_reduce_idx;
            $adjustByContract[$interval->idx]['ref_reduce_no'] = $interval->no;

            $start_date = Carbon::parse(Carbon::createFromTimestamp($interval->start_date)->format("Y-m-d"))->addHours($interval->start_h)->format("Y-m-d H:i:s");
            $adjustByContract[$interval->idx]['YMDH'] = $start_date;

            // 당월 기본정산금단가
            $adjustByContract[$interval->idx]['BP'] = $this->getCacheBPbyYm($month, $interval->dr_idx);

            // 익월 기본정산금단가
            $adjustByContract[$interval->idx]['NextBP'] = $this->getCacheBPbyYm($nextMonth, $interval->dr_idx);

            $orc = (int)$interval->dr + (int)$interval->test + (int)$interval->request + (int)$interval->peak + (int)$interval->dust;
            // 당월확정 고정 기본정산금

            $adjustByDr[$interval->idx]['ORC'] = $orc;

            // FFSF 당월 고정연료 전환성과계수 =? 2020~-01 / 2020-06
            // TODO ffsf 관리 테이블 필요하다
            $adjustByContract[$interval->idx]['FFSF'] = 1;
            $adjustByContract[$interval->idx]['DFSF'] = 1;

            // ORC * BP * FFSF = 당월확정 고정 기본정산금 (FBP)
            $adjustByContract[$interval->idx]['FBP'] = round($adjustByContract[$interval->idx]['ORC'] * $adjustByContract[$interval->idx]['BP'] * 1, 0);

            // 익월 고정기본 정산금 (FBP)
            $adjustByContract[$interval->idx]['NextFBP'] = round($adjustByContract[$interval->idx]['ORC'] * $adjustByContract[$interval->idx]['NextBP'] * 1, 0);

//            // 당월확정 차등 기본 정산금 DRDBP
//            $adjustByContract[$interval->idx]['NextFBP'] = round($adjustByContract[$interval->idx]['ORC'] * $adjustByContract[$interval->idx]['NextBP'] * 1, 0);

            // 차등 기본 정산금 ORC * BP * (FFSF - DFSF)
            // TODO FFSF - DFSF 값 넣어줘야하고, 관리 테이블 필요함
            $adjustByContract[$interval->idx]['DBP'] = $adjustByContract[$interval->idx]['ORC'] * $adjustByContract[$interval->idx]['BP'] * ($adjustByContract[$interval->idx]['DFSF'] - $adjustByContract[$interval->idx]['FFSF']);

            // 당월 확정 차등 기본정산금
            $adjustByContract[$interval->idx]['DRDBP'] = ($adjustByContract[$interval->idx]['DBP'] - (isset($DrDBPBynextMonth) ? $DrDBPBynextMonth : 0) ) * $DBPF;

            // 기본 정산금
            $adjustByContract[$interval->idx]['DRBP'] = $adjustByContract[$interval->idx]['FBP'] + $adjustByContract[$interval->idx]['DRDBP'];

            $where = [
                'YM'                => $month,
                'YMDH'              => $start_date,
                'type'              => 'default',
                'ref_contract_idx'  => $interval->ref_contract_idx,
                'ref_reduce_idx'    => $interval->ref_reduce_idx,
                'ref_reduce_no'     => $interval->no,
            ];

            // 일단 없애기
            unset($adjustByContract[$interval->idx]['NextBP']);
            unset($adjustByContract[$interval->idx]['NextFBP']);
            unset($adjustByContract[$interval->idx]['DBP']);

            $wattage = $this->getReduceFromWattageByContract($interval->ref_reduce_idx, $interval->contract_idx, $interval->no);

            $adjustByContract[$interval->idx]['DCBL'] = $wattage->cbl_dr;
            $adjustByContract[$interval->idx]['SCBL'] = $wattage->cbl_sr;
            $adjustByContract[$interval->idx]['ME'] = empty($wattage->v) ? 0 : $wattage->v;

            $this->adjustDetailUpdateOrCreate($where, $adjustByContract[$interval->idx], AdjustContractDetail::class);



            // 계획감축 & 급전인지 확인 필요

            // 급전감축량, 의무감축요청량
            // TODO value null check
            $cblSr = isset($wattage['cbl_sr']) && !empty($wattage['cbl_sr']) ? $wattage['cbl_sr'] : 0;
            $cblDr = isset($wattage['cbl_dr']) && !empty($wattage['cbl_dr']) ? $wattage['cbl_dr'] : 0;
            $v = isset($wattage['v']) && !empty($wattage['v']) ? $wattage['v'] : 0;

            // 자발적 dr 이랑 포함되어있는지 체크해보기
            $reduceType = explode(",", $interval->reduce_type);

            $adjustByContract[$interval->idx]['DR'] = 0;
            $adjustByContract[$interval->idx]['DLR'] = 0;
            $adjustByContract[$interval->idx]['DRP'] = 0;

            // 자발적 DR (경제성 Dr, 미세먼지, 피크수요)

            if (count(array_intersect(array(0, 4), $reduceType)) > 0) {
                // CBL
                $adjustByContract[$interval->idx]['DCBL'] = $cblDr;

                // RSO 의무감축요청량
                $adjustByContract[$interval->idx]['RSO'] = !empty($interval->reduce_order_qty) ? $interval->reduce_order_qty : 0;
                // DR = 급전감축량 (DCBL - 사용량)
                $adjustByContract[$interval->idx]['DR'] = $cblDr - $v;

                // DLR = 급전감축 감축량 인정량 (PSR SCBL - 사용량)
                $adjustByContract[$interval->idx]['DLR'] = $cblDr - $v > 0 ? $cblDr - $v : 0;

                // DRP = 급전감축 실척정산금(DRP)
                $quantity = $interval->reduce_order_qty > ($cblDr - $v) ? ($cblDr - $v) : $interval->reduce_order_qty;
                $adjustByContract[$interval->idx]['DRP'] = min(($cblDr - $v), ($quantity * 1.2)) * $interval->ri_adjust_price;

                /**
                 * 위약금 관련된 부분
                 */

                // TODO 전력거래시간 기본정산금 단가 (DRHCF, kW-m) - 해당자원 합
//                $originPrice = 294.1;
                $originPrice = 10000;

                // 최대감축시간 MRT
                $adjustByContract[$interval->idx]['MRT'] = $interval->duration;

                // 전력거래월수 DRTM
                $drtm = 0;
                if ($interval->duration == 60) {
                    $drtm = 12;
                } else if ($interval->duration == 30) {
                    $drtm = 6;
                }

                $adjustByContract[$interval->idx]['DRTM'] = $drtm;

                // DRD 급전감축 미이행량 DRD max(의무감축용량 * 0.97 - (cbl - v), 0)
                $adjustByContract[$interval->idx]['DRD'] = max(($interval->reduce_order_qty * 0.97) - ($cblDr - $v), 0);

                // 급전감축 위약금 계수 2 -> default tptxld
                $adjustByContract[$interval->idx]['BPCF'] = 2;

                // 기본정산금 상한 적용전 기본위약금 (IBPC)
                // 정산금 단가 / (최대감축시간 / 전력거래월수) * 급전감축 미이행량 * 급전감축 위약금 계수
                // DRHCF / (MRT / DRTM) * DRD * DPCF
                $ibpc = $originPrice / ($interval->duration / $drtm) * $adjustByContract[$interval->idx]['DRD'] * $adjustByContract[$interval->idx]['BPCF'];

                $adjustByContract[$interval->idx]['IBPC'] = $ibpc;

                // 전월 ibpc 가지고오는거
                // TODO 고객으로 전월 가지고 오는거 시간별인데 어떤걸 기준으로 가지고 와야하는지 문의
                // 일단 0으로 해서 계산하자
                // 0 이 아니라 ym 하고, contract_idx 넣어준다.
                $adjustByContract[$interval->idx]['previousMonthIBPC'] = 0;
                $adjustByContract[$interval->idx]['previousMonthIFBPC'] = 0;

                /**
                 * ibpc 전 달 가지고오기
                 * ifbpc도 전 달
                 */
                $previousMonthIBPC = 12197300;
                $previousMonthIFBPC = 0;

                // 전월에서 이월된 고정기본정산금 한도의 기본위약금 (FBPCC)
                $adjustByContract[$interval->idx]['FBPCC'] = min(($previousMonthIBPC - $previousMonthIFBPC), $adjustByContract[$interval->idx]['FBP']);

                // 고정기본정산금 한도의 기본 위약금 IFBPC
                $adjustByContract[$interval->idx]['IFBPC'] = min($ibpc, $adjustByContract[$interval->idx]['FBP'] - $adjustByContract[$interval->idx]['FBPCC']);

                // 고정기본정산금 위약금 한도 FBPC
                // FBPCC + IFBPC
                $adjustByContract[$interval->idx]['FBPC'] = $adjustByContract[$interval->idx]['FBPCC'] + $adjustByContract[$interval->idx]['IFBPC'];

                // 고정기본정산금 한고의 기본위약금 (익월 FBPCC)
                // min(IBPC - IFBPC, NextFBP)
                $adjustByContract[$interval->idx]['nextFBPCC'] = min( ($ibpc -$adjustByContract[$interval->idx]['IFBPC']) ,$adjustByContract[$interval->idx]['NextFBP']);

                $adjustByContract[$interval->idx]['RBPC'] = $adjustByContract[$interval->idx]['IBPC'] - $adjustByContract[$interval->idx]['IFBPC'] - $adjustByContract[$interval->idx]['nextFBPCC'];

                // DBPCC
                // min(previousRBPC - IDBPC, DBP)
                // DBP = 차등기본정산금

                // 전월 rbpc
                $adjustByContract[$interval->idx]['previousRBPC'] = 0;
                $adjustByContract[$interval->idx]['previousIDBPC'] = 0;
                $adjustByContract[$interval->idx]['DBPCC'] = min(($adjustByContract[$interval->idx]['previousRBPC'] - $adjustByContract[$interval->idx]['previousIDBPC']), $adjustByContract[$interval->idx]['DBP']);
                $adjustByContract[$interval->idx]['IDBPC'] = min($adjustByContract[$interval->idx]['RBPC'], ($adjustByContract[$interval->idx]['DBP'] - $adjustByContract[$interval->idx]['DBPCC']));

                $adjustByContract[$interval->idx]['nextDBPC'] = 0;

                // 정산금 위약금 한도
                $adjustByContract[$interval->idx]['DBPC'] = ($adjustByContract[$interval->idx]['DBPCC'] + $adjustByContract[$interval->idx]['IDBPC'] - $adjustByContract[$interval->idx]['nextDBPC']) * $DBPF;
                $adjustByContract[$interval->idx]['BPC'] = $adjustByContract[$interval->idx]['FBPC'] + $adjustByContract[$interval->idx]['DBPC'];

                // 차등기본 정산금 위약금 한도 (DBPC)
                $adjustByContract[$interval->idx]['DBPC'] = $adjustByContract[$interval->idx]['IBPC'] - $adjustByContract[$interval->idx]['IFBPC'] - $adjustByContract[$interval->idx]['nextFBPCC'];

                // 급전감축 위약금 BPC
                // 위약금 한도(FBPC) + DBPC
                $adjustByContract[$interval->idx]['BPC'] = $adjustByContract[$interval->idx]['FBPC'] + $adjustByContract[$interval->idx]['DBPC'];
            }

            if (count(array_intersect($voluntaryReduce, $reduceType)) > 0) {
                // PSR 경제성 DR 감축량 cbl - v

                $isDrEvent = 0;

//                // 같은 시간대의 급전이 있었는지 체크하기
//                $drEventInfo = $this->getDrEventByReduceHour($interval->ref_contract_idx, $interval->dr_id, $interval->start_date, $interval->start_h);
//
//                if (!empty($drEventInfo)) {
//
//                    $isDrEvent = 1;
//                    $adjustByContract[$interval->idx]['DR'] = $wattage['cbl_dr'] - $wattage['v'];
//
//                    $dr = ($wattage['cbl_dr'] - $wattage['v']) > 0 ? $wattage['cbl_dr'] - $wattage['v'] : 0;
//                    $adjustByContract[$interval->idx]['DLR'] = min($dr, ($drEventInfo->reduce_order_qty * 1.2)) * (1 - $isDrEvent) + max($dr, 0) * $isDrEvent;
//                    $drp = min($dr, ($interval->reduce_order_qty * 1.2)) * $drEventInfo->mgp;
//                    $adjustByContract[$interval->idx]['DRP'] = $drp;
//                }

                // group by reduce_type 해놓고,
                // 1, 7, 8 등등 있으면 true 배열로 넣어놓고 값
                if (in_array(1, $reduceType)) {

                    $adjustByContract[$interval->idx]['PSRF'] = (int) $interval->request > 0 ? true : false;

                    // 피크수요 참여 여부
                    $adjustByContract[$interval->idx]['DSRF'] = (int) $interval->peak > 0 ? true : false;

                    // 미세먼지 참여여부
                    $adjustByContract[$interval->idx]['ESRF'] = (int) $interval->dust > 0 ? true : false;

                    $dedf = false;

                    if ($adjustByContract[$interval->idx]['PSRF'] && $adjustByContract[$interval->idx]['DSRF'] && $adjustByContract[$interval->idx]['ESRF']) {
                        $dedf = true;
                    }

                    // 경제성 DR 참여한 고객이 피크수요 OR 미세먼지에 참여여부
                    $adjustByContract[$interval->idx]['DEDF'] = $dedf;

                    $adjustByContract[$interval->idx]['PSSR'] = (int) $interval->request > 0 ? $interval->request : 0;

                    $adjustByContract[$interval->idx]['PSR'] = $cblSr - $v;

                    // min(max(경제성dr 감축인정량 - 급전감축량, 0), 경제성 dr 감축계획량)
                    $adjustByContract[$interval->idx]['PSLR'] = min(max($cblSr - $v, 0), (int)$interval->request);

                    // smp * 감축 인정량 ** SMP ==>adjust_price
                    // 경제성 DR 실적 정산금 (PSLRP)
                    $adjustByContract[$interval->idx]['PSLRP'] = round($adjustByContract[$interval->idx]['PSLR'] * $interval->ri_adjust_price, 1);
                }


                // 피크수요 있는지 체크

                if (in_array(7, $reduceType)) {
                    // 피크수요
                    // 감축계획

                    // 동시참여 감축량
                    $adjustByContract[$interval->idx]['PDSR'] = $cblSr - $v;

                    $adjustByContract[$interval->idx]['DESR'] = $interval->peak;

                    // 피크수요 감축량
                    $adjustByContract[$interval->idx]['DSR'] = ($cblSr - $v) * (int)$adjustByContract[$interval->idx]['DSRF'] * (1 - (int)$adjustByContract[$interval->idx]['PSRF']);

                    // 경제성 피크수요 DR 미세먼지 동시참여자 감축량
                    $dlr = isset($adjustByContract[$interval->idx]['DLR']) ? $adjustByContract[$interval->idx]['DLR'] : 0;

                    // 피크수요 DR 감축인정량 (DSLR)
                    $adjustByContract[$interval->idx]['DSLR'] = min(max(($adjustByContract[$interval->idx]['PDSR'] - $interval->request) * 1 + $adjustByContract[$interval->idx]['DSR'] - $dlr, 0), $interval->peak);
                    $adjustByContract[$interval->idx]['DSLRP'] = round($adjustByContract[$interval->idx]['DSLR'] * $interval->ri_adjust_price, 1);
                }

                // 미세먼지 체크
                if (in_array(8, $reduceType)) {
                    // 피크수요
                    $adjustByContract[$interval->idx]['PESR'] = ($cblSr - $v) * (int)$adjustByContract[$interval->idx]['PSRF'] * (int) $adjustByContract[$interval->idx]['ESRF'];
                    $adjustByContract[$interval->idx]['EMSR'] = $interval->dust;

                    // 미세먼지 감축량
                    $adjustByContract[$interval->idx]['ESR'] = ($cblSr - $v) * (int)$adjustByContract[$interval->idx]['ESRF'] * (1 - (int)$adjustByContract[$interval->idx]['PSRF']);

                    $eslr = min(max(($adjustByContract[$interval->idx]['PESR'] - $adjustByContract[$interval->idx]['PSLR']) * (int) $adjustByContract[$interval->idx]['DEDF'] + $adjustByContract[$interval->idx]['ESR'] - $adjustByContract[$interval->idx]['DSLR'], 0), $interval->dust);
                    $adjustByContract[$interval->idx]['ESLR'] = $eslr;
                    $adjustByContract[$interval->idx]['ESLRP'] = $eslr * $interval->ri_adjust_price;
                }

                $xdresmp = max($interval->ri_price * min(max((int)$adjustByContract[$interval->idx]['PSR'] - (int)$adjustByContract[$interval->idx]['DSR'], 0), $adjustByContract[$interval->idx]['PSSR']) * 1000 - $adjustByContract[$interval->idx]['PSLRP'], 0);
                $adjustByContract[$interval->idx]['XDRESMP'] = $xdresmp;
                $adjustByContract[$interval->idx]['SLRP'] = $adjustByContract[$interval->idx]['PSLRP'] + $adjustByContract[$interval->idx]['DSLRP'] + $adjustByContract[$interval->idx]['ESLRP'] + $adjustByContract[$interval->idx]['XDRESMP'];

                $adjustByContract[$interval->idx]['PPPC'] = ($adjustByContract[$interval->idx]['PSSR'] - $adjustByContract[$interval->idx]['PSLR']) * $interval->ri_adjust_price;
                $adjustByContract[$interval->idx]['DPPC'] = ($adjustByContract[$interval->idx]['DESR'] - $adjustByContract[$interval->idx]['DSLR']) * $interval->ri_adjust_price;
                $adjustByContract[$interval->idx]['EPPC'] = ($adjustByContract[$interval->idx]['EMSR'] - $adjustByContract[$interval->idx]['ESLR']) * $interval->ri_adjust_price;
                $adjustByContract[$interval->idx]['PPC'] = $adjustByContract[$interval->idx]['PPPC'] + $adjustByContract[$interval->idx]['DPPC'] + $adjustByContract[$interval->idx]['EPPC'];
            }


            $where = [
                'ref_contract_idx' => $interval->ref_contract_idx,
                'ref_reduce_idx' => $interval->ref_reduce_idx,
                'ref_reduce_no' => $interval->no,
                'sr_ref_reduce_idx' => $interval->sr_ref_reduce_idx,
                'sr_ref_reduce_no' => $interval->sr_ref_reduce_no,
                'YM'                => $month,
                'YMDH'              => $start_date,
                'type' => 'profit'
            ];

            /**
             * # DRP 급전실적, DRBP 기본정산금, BPC 급전위약금
            #
            # SLRP 계획실적, XDRESMP 계획추가실적, PPC 계획위약금
             */

            $this->adjustDetailUpdateOrCreate($where, $adjustByContract, AdjustContractDetail::class);
        }
    }

    private function getReduceIntervalOfContract($month = "", $company_id = 1) {

        $ym = Carbon::parse($month)->format('Ym');

        $reduce_contract_intervals_select = "
            _reduce_contract_interval.*,
            _reduce_interval.no AS ri_no,
            _reduce_interval.price AS ri_price,
            _reduce_interval.adjust_price AS ri_adjust_price,
            _reduce_interval.MGP AS mgp,
            _reduce.start_date,
            group_concat(_reduce.reduce_type order by _reduce.reduce_type asc)              as reduce_type,
            _dr.idx as dr_idx,
            _dr.id as dr_id,
            date_format(from_unixtime(_reduce.start_date), '%Y-%m-%d') as date,
            _dr.reduce_hour as dr_reduce_hour,
            _contract.idx as contract_idx,
            _contract.name as contract_name,
            _contract_capacity.capacity as contract_capacity,
            adjust_default_rates.idx as adjust_idx,
            adjust_default_rates.d_default,
            adjust_default_rates.d_profit,
            adjust_default_rates.d_break,
            adjust_default_rates.s_profit,
            adjust_default_rates.s_over_profit,
            adjust_default_rates.s_break,
            adjust_smp_prices.price as adjust_smp_price,
            max(if(_reduce.reduce_type = 0, _reduce_contract_interval.reduce_order_qty, 0)) as dr,
            max(if(_reduce.reduce_type = 1, _reduce_contract_interval.reduce_order_qty, 0)) as request,
            max(if(_reduce.reduce_type = 4, _reduce_contract_interval.reduce_order_qty, 0)) as test,
            max(if(_reduce.reduce_type = 7, _reduce_contract_interval.reduce_order_qty, 0)) as peak,
            max(if(_reduce.reduce_type = 8, _reduce_contract_interval.reduce_order_qty, 0)) as dust   
        ";

        $reduce_contract_intervals = ReduceContractInterval::selectRaw($reduce_contract_intervals_select)
            ->leftJoin('_contract', '_reduce_contract_interval.ref_contract_idx', '_contract.idx')
            ->leftJoin('_reduce', '_reduce_contract_interval.ref_reduce_idx', '_reduce.idx')
            ->leftJoin('_dr', '_reduce.ref_dr_id', '_dr.id')
            ->leftJoin('_reduce_interval', function ($join) {
                $join->on('_reduce_interval.ref_reduce_idx', '=', '_reduce_contract_interval.ref_reduce_idx')
                    ->on('_reduce_interval.start_h', '=', '_reduce_contract_interval.start_h')
                    ->on('_reduce_interval.start_m', '=', '_reduce_contract_interval.start_m');
            })
            ->leftJoin('_contract_capacity', function ($join) use ($ym) {
                $join->on('_contract.idx', '=', '_contract_capacity.ref_contract_idx')->where('ym', $ym);
            })
            ->leftJoin('adjust_default_rates', function ($join) {
                $join->on('_contract.idx', '=', 'adjust_default_rates.c_idx')
                    ->where('type', 'Contract')
                    ->whereRaw('FROM_UNIXTIME(_reduce.start_date) BETWEEN adjust_default_rates.start_date AND adjust_default_rates.end_date');
            })
            ->leftJoin('adjust_smp_prices', function ($join) {
                $join->on(DB::raw("date_format(from_unixtime(_reduce.start_date), '%Y%m%d')"), '=', "adjust_smp_prices.ymd")
                    ->on(DB::raw("LPAD((_reduce_interval.start_h + 1), 2, '0')"), '=', 'adjust_smp_prices.h');
            })
            ->whereNotNull('_reduce.idx')
            ->where('_contract.company_id', $company_id)
            ->whereBetween('_reduce.start_date', [
                Carbon::parse($month)->firstOfMonth()->timestamp,
                Carbon::parse(Carbon::parse($month)->lastOfMonth()->format('Y-m-d 23:59:59'))->timestamp
            ])
            ->whereIn('_reduce.reduce_type', [0, 1, 4, 7, 8])
            ->whereRaw("? BETWEEN date_format(_dr.start_date, '%Y-%m') AND date_format(_dr.end_date, '%Y-%m')", $month)
            ->groupBy('_reduce_contract_interval.start_h', '_reduce_contract_interval.ref_contract_idx', '_reduce.ref_dr_id')
            ->groupBy(DB::raw('date_format(from_unixtime(_reduce.start_date), "%Y-%m-%d")'))
            ->orderByRaw('date_format(from_unixtime(_reduce.start_date), "%Y-%m-%d") asc, _reduce_contract_interval.start_h asc, _reduce_contract_interval.start_m asc')
            ->get();

        if (empty($reduce_contract_intervals)) {
            return false;
        }

        return $reduce_contract_intervals;
    }

    private function getReduceIntervalOfDr($month = "", $companyId = 1) {
        $select = "
        _reduce_interval.*,
        group_concat(_reduce.reduce_type)                                      as reduce_type,
        _reduce_interval.adjust_price                                          as ri_adjust_price,
        _reduce_interval.price                                                 as ri_price,
        _reduce.start_date,
        _reduce.dr_reduce_adjust_capacity,
        _dr.idx as dr_idx,
        _dr.name as dr_name,
        _dr.id as dr_id,
        _dr.reduce_hour as dr_reduce_hour,
        adjust_smp_prices.price as adjust_smp_price,
        max(if(_reduce.reduce_type = 0, _reduce_interval.reduce_order_qty, 0)) as dr,
        max(if(_reduce.reduce_type = 1, _reduce_interval.reduce_order_qty, 0)) as request,
        max(if(_reduce.reduce_type = 4, _reduce_interval.reduce_order_qty, 0)) as test,
        max(if(_reduce.reduce_type = 7, _reduce_interval.reduce_order_qty, 0)) as peak,
        max(if(_reduce.reduce_type = 8, _reduce_interval.reduce_order_qty, 0)) as dust
        ";

        $reduceIntervals = ReduceInterval::selectRaw($select)
            ->leftJoin('_reduce', '_reduce_interval.ref_reduce_idx', '_reduce.idx', '_reduce.start_date')
            ->leftJoin('_dr', '_reduce.ref_dr_id', '_dr.id')
            ->leftJoin('adjust_smp_prices', function ($join) {
                $join->on(DB::raw("date_format(from_unixtime(_reduce.start_date), '%Y%m%d')"), '=', "adjust_smp_prices.ymd")
                    ->on(DB::raw("LPAD((_reduce_interval.start_h + 1), 2, '0')"), '=', 'adjust_smp_prices.h');
            })
            ->where('_dr.company_id', $companyId)
            ->whereBetween('_reduce.start_date', [
                Carbon::parse($month)->firstOfMonth()->timestamp,
                Carbon::parse(Carbon::parse($month)->lastOfMonth()->format('Y-m-d 23:59:59'))->timestamp
            ])
            ->whereRaw("? BETWEEN date_format(_dr.start_date, '%Y-%m') AND date_format(_dr.end_date, '%Y-%m')", $month)
            ->whereRaw("date_format(from_unixtime(_reduce.start_date), '%Y-%m-%d') = '2020-03-20'")
            ->whereIn('_reduce.reduce_type', [0, 1, 4, 7, 8])
            ->groupBy(DB::raw('date_format(from_unixtime(_reduce.start_date), "%Y-%m-%d")'))
            ->groupBy('_reduce.ref_dr_id', '_reduce_interval.start_h')
            ->get();

        if (empty($reduceIntervals)) {
            return false;
        }

        return $reduceIntervals;
    }

    /**
     * 참여고객 기준으로 같은 시간대의 급전이 있는지 확인해준다.
     * return 수요감축용량,
     * @param int $contractIdx
     * @param string $drId
     * @param int $startDate
     * @param int $hour
     * @return bool
     */
    private function getDrEventByReduceHour($contractIdx = 0, $drId = '', $startDate = 0, $hour = 0) {

        $query = "
            select rci.reduce_order_qty, ri.mgp, r.idx
            from `_reduce` r
              inner join `_reduce_contract_interval` rci on r.idx = rci.ref_reduce_idx
              inner join `_reduce_interval` ri on ri.ref_reduce_idx = r.idx
                  and rci.start_h = ri.start_h and rci.start_m = ri.start_m
            where reduce_type in (0, 4)
                  and rci.ref_contract_idx = ?
                  and r.start_date >= unix_timestamp(date_format(from_unixtime(?), '%Y-%m-%d 00:00:00'))
                  and r.start_date <= unix_timestamp(date_format(from_unixtime(?), '%Y-%m-%d 23:59:59'))
                  and r.ref_dr_id = ?
                  and rci.start_h = ?
        ";

        $event = DB::select($query, [$contractIdx, $startDate, $startDate, $drId, $hour]);

        if (empty($event)) {
            return array();
        }

        return $event[0];
    }

    /**
     * 관리사원 월별 정산처리
     * Create by tk.kim 2017.08
     *
     * @param $month
     * @param $company_id
     * @return array|bool
     */
    public function setUserAdjustsForMonth($month, $company_id = 1)
    {
        $ym = Carbon::parse($month)->format('Ym');
        $company_id = session('admin_login_info.company_id', $company_id);
        $drs = Dr::select('idx')->where('company_id', $company_id)->get();
        if ($this->getAdjustIsPayed($ym, AdjustUserDetail::class, $drs)) return true;
        AdjustUserDetail::where('ym', $ym)->whereIn('ref_dr_idx', $drs)->delete();
        $selectRaw = "
                _user.idx as user_idx,
                _user.name as user_name,
                adjust_default_rates.idx as adjust_idx,
                adjust_default_rates.d_profit as PAY_DPT,
                0 as PAY_DPP,
                adjust_default_rates.s_profit as PAY_SPT,
                0 as PAY_SPP,
                adjust_default_rates.s_over_profit as PAY_SOPT,
                0 as PAY_SOPP,
                adjust_default_rates.s_break as PAY_SBT,
                0 as PAY_SBP,
                adjust_default_rates.d_default as PAY_DDT,
                0 as PAY_DDP,
                adjust_default_rates.d_break as PAY_DBT,
                0 as PAY_DBP,
                adjust_default_rates.u_idx as ref_user_idx,
                adjust_default_rates.c_idx as ref_contract_idx,
                adjust_contract_details.ref_dr_idx,
                adjust_contract_details.ref_reduce_idx,
                adjust_contract_details.ref_reduce_no,
                adjust_contract_details.type,
                adjust_contract_details.YM,
                adjust_contract_details.YMDH,
                adjust_contract_details.ORC,
                adjust_contract_details.RSO,
                adjust_contract_details.XRSOF,
                adjust_contract_details.DCBL,
                adjust_contract_details.ME,
                adjust_contract_details.DR,
                adjust_contract_details.SMP,
                adjust_contract_details.DF,
                adjust_contract_details.DRP,
                adjust_contract_details.SCBL,
                adjust_contract_details.SR,
                adjust_contract_details.PSSR,
                adjust_contract_details.SLRP,
                adjust_contract_details.DRRP,
                adjust_contract_details.XDRESMP,
                adjust_contract_details.PPCF,
                adjust_contract_details.PPC,
                adjust_contract_details.sr_ref_reduce_idx,
                adjust_contract_details.sr_ref_reduce_no,
                adjust_contract_details.BP,
                adjust_contract_details.DRBP,
                adjust_contract_details.TDRBP,
                adjust_contract_details.TDRBP_VALUE,
                adjust_contract_details.MRT,
                adjust_contract_details.DRD,
                adjust_contract_details.BPCF,
                adjust_contract_details.IBPC,
                adjust_contract_details.BPC
            ";

        $adjust_contract_details = AdjustContractDetail::selectRaw($selectRaw)
            ->rightJoin('adjust_default_rates', function ($join) {
                $join->on('adjust_default_rates.c_idx', '=', 'adjust_contract_details.ref_contract_idx')
                    ->where('adjust_default_rates.type', 'User')
                    ->whereRaw('adjust_contract_details.YMDH BETWEEN adjust_default_rates.start_date AND adjust_default_rates.end_date');
            })
            ->leftJoin('_user', 'adjust_default_rates.u_idx', '_user.idx')
            ->where('adjust_contract_details.YM', $ym)
            ->where('_user.company_id', $company_id)
            ->get()
            ->toArray();

        $adjust_default_rate_null_users = [];
        foreach ($adjust_contract_details as $adjust_contract_detail) {
            if (!is_null($adjust_contract_detail['adjust_idx'])) continue;
            $adjust_default_rate_null_users[$adjust_contract_detail['ref_user_idx']] = $adjust_contract_detail['user_name'];
        }
        if (!empty($adjust_default_rate_null_users)) return ['code' => 400, 'message' => '관리사원 [' . implode('], [', array_values($adjust_default_rate_null_users)) . '] 은 해당 월에 지급율이 없으므로 정산을 정상적으로 진행 하지 못했습니다. 먼저 지급율을 등록후 다시 시도 하십시오. '];

        $calculate_method = 'getCalculateProfitAdjust';
        foreach ($adjust_contract_details as $adjust_contract_detail) {
            unset($adjust_contract_detail['adjust_idx'], $adjust_contract_detail['user_name'], $adjust_contract_detail['user_idx']);
            $adjust_calculate_params = $adjust_contract_detail;
            if ($adjust_calculate_params['type'] == 'default') $calculate_method = 'getCalculateDefaultAdjust';
            $this->$calculate_method($adjust_calculate_params);
            if (!is_null($adjust_contract_detail['sr_ref_reduce_idx'])) $this->getCalculateProfitAdjust($adjust_calculate_params);

            $where = [
                'ref_user_idx' => $adjust_calculate_params['ref_user_idx'],
                'ref_contract_idx' => $adjust_calculate_params['ref_contract_idx'],
                'ref_reduce_idx' => $adjust_calculate_params['ref_reduce_idx'],
                'ref_reduce_no' => $adjust_calculate_params['ref_reduce_no'],
                'sr_ref_reduce_idx' => $adjust_calculate_params['sr_ref_reduce_idx'],
                'sr_ref_reduce_no' => $adjust_calculate_params['sr_ref_reduce_no'],
                'type' => $adjust_calculate_params['type']
            ];

            $this->adjustDetailUpdateOrCreate($where, $adjust_calculate_params, AdjustUserDetail::class);
            unset($where, $adjust_calculate_params);
        }
        return true;
    }

    /**
     * 한국엔텍 관리사원 월별 정산처리
     * Create by tk.kim 2017.09
     *
     * @param $month
     * @param $company_id
     * @return array|bool
     */
    public function setKeUserAdjustsForMonth($month, $company_id = 1)
    {
        $ym = Carbon::parse($month)->format('Ym');
        $company_id = session('admin_login_info.company_id', $company_id);
        $drs = Dr::select('idx')->where('company_id', $company_id)->get();
        if ($this->getAdjustIsPayed($ym, AdjustKeUserDetail::class, $drs)) return true;
        AdjustKeUserDetail::where('ym', $ym)->whereIn('ref_dr_idx', $drs)->delete();
        $selectRaw = "
            _dr.start_date,
            _dr.end_date,
            _user.idx AS ref_user_idx,
            _user.name AS user_name,
            adjust_ke_user_rates.id as adjust_idx,
            adjust_ke_user_rates.type as user_type,
            adjust_ke_user_rates.d_profit as PAY_DPT,
            0 as PAY_DPP,
            adjust_ke_user_rates.s_profit as PAY_SPT,
            0 as PAY_SPP,
            adjust_ke_user_rates.s_over_profit as PAY_SOPT,
            0 as PAY_SOPP,
            adjust_ke_user_rates.d_default as PAY_DDT,
            0 as PAY_DDP,
            adjust_contract_details.PAY_DPT as PAY_CDPT,
            0 as PAY_CDPP,
            adjust_contract_details.PAY_SPT as PAY_CSPT,
            0 as PAY_CSPP,
            adjust_contract_details.PAY_SOPT as PAY_CSOPT,
            0 as PAY_CSOPP,
            adjust_contract_details.PAY_DDT as PAY_CDDT,
            0 as PAY_CDDP,
            0 as PAY_DDPP,
            0 as PAY_DSPP,
            0 as PAY_DSOPP,
            adjust_contract_details.ref_dr_idx,
            adjust_contract_details.ref_contract_idx,
            adjust_contract_details.ref_reduce_idx,
            adjust_contract_details.ref_reduce_no,
            adjust_contract_details.type,
            adjust_contract_details.YM,
            adjust_contract_details.YMDH,
            adjust_contract_details.ORC,
            adjust_contract_details.RSO,
            adjust_contract_details.DCBL,
            adjust_contract_details.ME,
            adjust_contract_details.DR,
            adjust_contract_details.SMP,
            adjust_contract_details.DF,
            adjust_contract_details.DRP,
            adjust_contract_details.SCBL,
            adjust_contract_details.SR,
            adjust_contract_details.PSSR,
            adjust_contract_details.SLRP,
            adjust_contract_details.DRRP,
            adjust_contract_details.XDRESMP,
            adjust_contract_details.sr_ref_reduce_idx,
            adjust_contract_details.sr_ref_reduce_no,
            adjust_contract_details.BP,
            adjust_contract_details.DRBP,
            adjust_contract_details.TDRBP
        ";

        # profit
        $adjust_contract_details = AdjustContractDetail::selectRaw($selectRaw)
            ->leftJoin('adjust_ke_user_rates', function ($join) {
                $join->on('adjust_ke_user_rates.c_idx', '=', 'adjust_contract_details.ref_contract_idx')
                    ->where('adjust_ke_user_rates.type', '!=', 'KET_01')
                    ->whereRaw('adjust_contract_details.YMDH BETWEEN adjust_ke_user_rates.start_date AND adjust_ke_user_rates.end_date');
            })
            ->leftJoin('_reduce', 'adjust_contract_details.ref_reduce_idx', '_reduce.idx')
            ->leftJoin('_user', 'adjust_ke_user_rates.u_idx', '_user.idx')
            ->leftJoin('_dr', 'adjust_contract_details.ref_dr_idx', '_dr.idx')
            ->where(['adjust_contract_details.YM' => $ym, 'adjust_contract_details.type' => 'profit', '_user.company_id' => $company_id])
//            ->where('adjust_contract_details.ref_contract_idx', 418)
            ->whereNotNull('adjust_ke_user_rates.id')
            ->get()->toArray();

        $adjust_default_rate_null_users = [];
        $adjust_drs = [];

        $adjust_contract_drs = AdjustContractDetail::select(['adjust_contract_details.ref_dr_idx', '_dr.start_date', '_dr.end_date'])
            ->leftJoin('_dr', '_dr.idx', 'adjust_contract_details.ref_dr_idx')
            ->where(['adjust_contract_details.YM' => $ym, '_dr.company_id' => $company_id])
            ->groupBy('adjust_contract_details.ref_dr_idx')
            ->get();

        foreach ($adjust_contract_drs as $adjust_contract_dr) {
            $adjust_drs[$adjust_contract_dr['ref_dr_idx']] = [
                'start_date' => $adjust_contract_dr['start_date'],
                'end_date' => $adjust_contract_dr['end_date'],
                'contracts' => []
            ];
        }

        if (!empty($adjust_default_rate_null_users)) return ['code' => 400, 'message' => '관리사원 [' . implode('], [', array_values($adjust_default_rate_null_users)) . '] 은 해당 월에 지급율이 없으므로 정산을 정상적으로 진행 하지 못했습니다. 먼저 지급율을 등록후 다시 시도 하십시오. '];

        foreach ($adjust_contract_details as $adjust_contract_detail) {
            unset($adjust_contract_detail['adjust_idx'], $adjust_contract_detail['user_name'], $adjust_contract_detail['user_idx']);
            $adjust_drs[$adjust_contract_detail['ref_dr_idx']]['contracts'][$adjust_contract_detail['ref_contract_idx']] = ['d_cost' => 0, 'c_cost' => 0];
            $adjust_calculate_params = $adjust_contract_detail;
            $this->getCalculateKeUserProfitAdjust($adjust_calculate_params);
            $where = [
                'ref_user_idx' => $adjust_calculate_params['ref_user_idx'],
                'ref_contract_idx' => $adjust_calculate_params['ref_contract_idx'],
                'ref_reduce_idx' => $adjust_calculate_params['ref_reduce_idx'],
                'ref_reduce_no' => $adjust_calculate_params['ref_reduce_no'],
                'sr_ref_reduce_idx' => $adjust_calculate_params['sr_ref_reduce_idx'],
                'sr_ref_reduce_no' => $adjust_calculate_params['sr_ref_reduce_no'],
                'YM' => $adjust_calculate_params['YM'],
                'type' => 'profit',
            ];

            unset($adjust_calculate_params['start_date'], $adjust_calculate_params['end_date']);
            $this->adjustDetailUpdateOrCreate($where, $adjust_calculate_params, AdjustKeUserDetail::class);
            unset($where, $adjust_calculate_params);
        }

        foreach ($adjust_drs as $key => $adjust_dr) {
            foreach ($adjust_dr['contracts'] as $contract_idx => $value) {
                $s_date = Carbon::parse($ym . '01')->toDateString();
                $e_date = Carbon::parse(Carbon::parse($s_date)->lastOfMonth()->format('Y-m-d 23:59:59'))->toDateString();
                if (Carbon::parse($s_date)->format('Ym') == Carbon::parse($adjust_dr['start_date'])->format('Ym')) $s_date = Carbon::parse($adjust_dr['start_date'])->toDateString();
                if (Carbon::parse($e_date)->format('Ym') == Carbon::parse($adjust_dr['end_date'])->format('Ym')) $e_date = Carbon::parse($adjust_dr['end_date'])->toDateString();
                $adjust_drs[$key]['contracts'][$contract_idx]['d_cost'] = AdjustKeDevicePrice::where(['ref_contract_idx' => $contract_idx, 'type' => 'd_cost'])->whereBetween('start_date', [$s_date, $e_date])->sum('price');
                $adjust_drs[$key]['contracts'][$contract_idx]['c_cost'] = AdjustKeDevicePrice::where(['ref_contract_idx' => $contract_idx, 'type' => 'c_cost'])->whereBetween('start_date', [$s_date, $e_date])->sum('price');
            }
        }

        # default
        $selectRaw = "
            adjust_contract_details.type,
            adjust_contract_details.YM,
            adjust_contract_details.YMDH,
            adjust_contract_details.ref_dr_idx,
            adjust_contract_details.ref_contract_idx,
            adjust_contract_details.ME,
            adjust_contract_details.ORC,
            adjust_contract_details.RSO,
            adjust_contract_details.DCBL,
            adjust_contract_details.DR,
            adjust_contract_details.DF,
            adjust_contract_details.DRP,
            adjust_contract_details.SMP,
            adjust_contract_details.SCBL,
            adjust_contract_details.SR,
            adjust_contract_details.PSSR,
            adjust_contract_details.SLRP,
            adjust_contract_details.DRRP,
            adjust_contract_details.XDRESMP,
            adjust_contract_details.PAY_SPT,
            adjust_contract_details.ORC,
            adjust_contract_details.BP,
            adjust_contract_details.DRBP,
            adjust_contract_details.TDRBP,
            adjust_contract_details.sr_ref_reduce_idx,
            adjust_contract_details.sr_ref_reduce_no,
            adjust_contract_details.PAY_DDT AS PAY_CDDT,
            0                               AS PAY_CDDP,
            0                               AS PAY_DDDP,
            0                               AS DVCDP,
            0                               AS DVCCP,
            0                               AS PAY_KDDP,
            CASE WHEN ket01.d_default IS NULL THEN 0 ELSE ket01.d_default END AS PAY_KDDT,
            ket.type                        AS user_type,
            ket.d_default                   AS PAY_DDT,
            0                               AS PAY_DDP,
            ket.d_profit                    AS PAY_DPT,
            0                               AS PAY_DPP,
            0                               AS RDC_RT_MAX,
            0                               AS RDC_RT,
            0                               AS RDC_TDRBP,
            user_ket.idx                    AS ref_user_idx
        ";

        # default
        $adjust_contract_details = AdjustContractDetail::selectRaw($selectRaw)
            ->leftJoin('adjust_ke_user_rates AS ket01', function ($join) {
                $join->on('ket01.c_idx', '=', 'adjust_contract_details.ref_contract_idx')
                    ->where('ket01.type', 'KET_01')
                    ->whereRaw('adjust_contract_details.YMDH BETWEEN ket01.start_date AND ket01.end_date');
            })
            ->leftJoin('_user AS user_ket01', 'user_ket01.idx', 'ket01.u_idx')
            ->leftJoin('adjust_ke_user_rates AS ket', function ($join) {
                $join->on('adjust_contract_details.ref_contract_idx', '=', 'ket.c_idx')
                    ->whereRaw('adjust_contract_details.YMDH BETWEEN ket.start_date AND ket.end_date');
            })
            ->leftJoin('_user AS user_ket', 'user_ket.idx', 'ket.u_idx')
            ->where(['adjust_contract_details.type' => 'default', 'adjust_contract_details.YM' => $ym, 'user_ket.company_id' => $company_id])
            ->get()->toArray();

        $s_date = null;
        $adjust_default_rate_null_users = [];
        foreach ($adjust_contract_details as $adjust_contract_detail) {
            if (!is_null($adjust_contract_detail['ref_user_idx'])) continue;
            $adjust_default_rate_null_users[$adjust_contract_detail['ref_user_idx']] = $adjust_contract_detail['ref_user_name'];
        }
        if (!empty($adjust_default_rate_null_users)) return ['code' => 400, 'message' => '관리사원 [' . implode('], [', array_values($adjust_default_rate_null_users)) . '] 은 해당 월에 지급율이 없으므로 정산을 정상적으로 진행 하지 못했습니다. 먼저 지급율을 등록후 다시 시도 하십시오. '];

        foreach ($adjust_contract_details as $adjust_contract_detail) {
            $adjust_calculate_params = $adjust_contract_detail;
            if (!isset($adjust_drs[$adjust_contract_detail['ref_dr_idx']]['contracts'][$adjust_contract_detail['ref_contract_idx']]['d_cost'])) {
                $s_date = Carbon::parse($ym . '01')->toDateString();
                $e_date = Carbon::parse(Carbon::parse($s_date)->lastOfMonth()->format('Y-m-d 23:59:59'))->toDateString();
                if (Carbon::parse($s_date)->format('Ym') == Carbon::parse($adjust_dr['start_date'])->format('Ym')) $s_date = Carbon::parse($adjust_dr['start_date'])->toDateString();
                if (Carbon::parse($e_date)->format('Ym') == Carbon::parse($adjust_dr['end_date'])->format('Ym')) $e_date = Carbon::parse($adjust_dr['end_date'])->toDateString();
                $adjust_drs[$adjust_contract_detail['ref_dr_idx']]['contracts'][$adjust_contract_detail['ref_contract_idx']]['d_cost'] = AdjustKeDevicePrice::where(['ref_contract_idx' => $adjust_contract_detail['ref_contract_idx'], 'type' => 'd_cost'])->whereBetween('start_date', [$s_date, $e_date])->sum('price');
                $adjust_drs[$adjust_contract_detail['ref_dr_idx']]['contracts'][$adjust_contract_detail['ref_contract_idx']]['c_cost'] = AdjustKeDevicePrice::where(['ref_contract_idx' => $adjust_contract_detail['ref_contract_idx'], 'type' => 'c_cost'])->whereBetween('start_date', [$s_date, $e_date])->sum('price');
            }
            $adjust_calculate_params['DVCDP'] = $adjust_drs[$adjust_contract_detail['ref_dr_idx']]['contracts'][$adjust_contract_detail['ref_contract_idx']]['d_cost'];
            $adjust_calculate_params['DVCCP'] = $adjust_drs[$adjust_contract_detail['ref_dr_idx']]['contracts'][$adjust_contract_detail['ref_contract_idx']]['c_cost'];
            $this->getCalculateKeUserDefaultAdjust($adjust_calculate_params);
            $where = [
                'ref_user_idx' => $adjust_calculate_params['ref_user_idx'],
                'ref_dr_idx' => $adjust_calculate_params['ref_dr_idx'],
                'ref_contract_idx' => $adjust_calculate_params['ref_contract_idx'],
                'sr_ref_reduce_idx' => $adjust_calculate_params['sr_ref_reduce_idx'],
                'sr_ref_reduce_no' => $adjust_calculate_params['sr_ref_reduce_no'],
                'YM' => $adjust_calculate_params['YM'],
                'type' => 'default',
            ];

            $this->adjustDetailUpdateOrCreate($where, $adjust_calculate_params, AdjustKeUserDetail::class);
            unset($where, $adjust_calculate_params);
        }

        return true;
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $idx
     * @param $price
     */
    private function updateReduceIntervalAdjustPrice($idx, $price)
    {
        ReduceInterval::find($idx)->update(['adjust_price' => $price]);
    }

    /**
     * 정산금 계산하기
     * Create by tk.kim 2017.07
     *
     * @param $param
     * @return mixed
     */
    private function getCalculateProfitAdjust(&$param)
    {
        if ($param['DF'] == 1) {
            # 급전
            $param['DRP'] = (min([$param['DR'], $param['RSO'] * 1.2]) * (1 - $param['XRSOF']) + $param['DR'] * $param['XRSOF']) * $param['SMP'] * $param['DF'];
            if ($param['DRP'] != 0) $param['DRP'] = round($param['DRP']);
            $param['PPC'] = max([$param['PSSR'] - $param['SR'], 0]) * $param['SMP'] * $param['PPCF'];
            if (isset($param['PAY_DPT']) and !empty($param['PAY_DPT'])) $param['PAY_DPP'] = round($param['DRP'] * ($param['PAY_DPT'] / 100), 2); # 전력수요 의무감축 정산금
            if (isset($param['PAY_DBT']) and !empty($param['PAY_DBT'])) $param['PAY_DBP'] = round($param['PPC'] * ($param['PAY_DBT'] / 100), 2); # 전력수요 의무감축 위약금
        } else {
            # 계획
            $param['SLRP'] = $param['SMP'] * min([
                    max([
                        $param['SR'] - (
                            min([
                                $param['DR'],
                                $param['RSO'] * 1.2
                            ]) * (1 - $param['XRSOF']) + $param['DR'] * $param['XRSOF']),
                        0]),
                    $param['PSSR']]);

            $xdre_smp =
                max([
                    (
                        ($param['DRRP'] * min([
                                max([
                                    $param['SR'] - min([$param['DR'], $param['RSO'] * 1.2]) * (1 - $param['XRSOF']) + $param['DR'] * $param['XRSOF'], 0
                                ]), $param['PSSR']
                            ])
                        ) - $param['SLRP']
                    ),
                    0
                ]);

            if (isset($param['PAY_SOPT'])) unset($param['PAY_SOPT']);
            $param['XDRESMP'] = ($param['PSSR'] >= 0) ? round($xdre_smp) : 0;
            if (isset($param['PAY_SOPP'])) $param['PAY_SOPP'] = $param['XDRESMP'];
            if (isset($param['PAY_SOPT']) and !empty($param['PAY_SOPT'])) $param['PAY_SOPP'] = round($param['XDRESMP'] * ($param['PAY_SOPT'] / 100), 2);
            $param['PPC'] = max([$param['PSSR'] - $param['SR'], 0]) * $param['SMP'] * $param['PPCF'];
            if (isset($param['PAY_SPT']) and !empty($param['PAY_SPT'])) $param['PAY_SPP'] = round(($param['SLRP'] + (isset($param['PAY_SOPP']) ? $param['PAY_SOPP'] : $param['XDRESMP'])) * ($param['PAY_SPT'] / 100), 2); # 계획감축 정산금
            if (isset($param['PAY_SBT']) and !empty($param['PAY_SBT'])) $param['PAY_SBP'] = round($param['PPC'] * ($param['PAY_SBT'] / 100), 2); # 계획감축 위약금
        }
        return $param;
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $param
     * @return mixed
     */
    private function getCalculateDefaultAdjust(&$param)
    {
        $param['DRBP'] = round($param['ORC'] * $param['BP']);
        if (isset($param['DRD']) && $param['DRD'] != 0) $param['DRD'] = round($param['DRD'], 3);
        # 2018-01-01 일 부터 위약금 계산 방식 신규 규정 적용 EIMS-1191
        $param['IBPC'] = 0;
        if (isset($param['YMDH']) and
            Carbon::parse($param['YMDH'])->timestamp < Carbon::parse('2018-01-01 00:00:00')->timestamp
        ) {
            # 17년도 규정
            if ($param['TDRBP'] != 0 and
                $param['ORC'] != 0 and
                $param['MRT'] != 0
            ) $param['IBPC'] = ($param['TDRBP'] / ($param['ORC'] * $param['MRT'])) * $param['DRD'] * $param['BPCF'] * $param['DF'];
        } else {
            if ($param['DRBP'] != 0 and
                $param['ORC'] != 0 and
                $param['MRT'] != 0
            ) $param['IBPC'] = ($param['DRBP'] / ($param['ORC'] * ($param['MRT'] / 12)) * $param['DRD'] * $param['BPCF'] * $param['DF']);
        }

        if ($param['IBPC'] != 0) $param['IBPC'] = round($param['IBPC']);
        $param['BPC'] = min($param['DRBP'], $param['IBPC']);
        if (!empty($param['PAY_DDT']) and !empty($param['PAY_DDT'])) $param['PAY_DDP'] = round($param['DRBP'] * ($param['PAY_DDT'] / 100), 2); # 기본 정산금
        if (!empty($param['PAY_DBT']) and !empty($param['PAY_DBT'])) $param['PAY_DBP'] = round($param['BPC'] * ($param['PAY_DBT'] / 100), 2); # 위약금
        return $param;
    }

    /**
     *
     * Create by tk.kim 2017.09
     *
     * @param $param
     * @return mixed
     */
    private function getCalculateKeUserProfitAdjust(&$param)
    {
        if ($param['DF'] == 1) {
            # dr
            $param['PAY_CDPP'] = round($param['DRP'] * ($param['PAY_CDPT'] / 100), 2);
            $param['PAY_DDPP'] = round($param['DRP'] - $param['PAY_CDPP'], 2);
            $param['PAY_DPP'] = round($param['PAY_DDPP'] * ($param['PAY_DPT'] / 100));
        } else {
            # sr
            $param['PAY_CSPP'] = round($param['SLRP'] * ($param['PAY_CSPT'] / 100));
            $param['PAY_DSPP'] = round($param['SLRP'] - $param['PAY_CSPP']);
            $param['PAY_SPP'] = round($param['PAY_DSPP'] * ($param['PAY_SPT'] / 100));
            $param['PAY_CSOPP'] = round($param['XDRESMP'] * ($param['PAY_CSPT'] / 100));
            $param['PAY_DSOPP'] = round($param['XDRESMP'] - $param['PAY_CSOPP']);
            $param['PAY_SOPP'] = round($param['PAY_DSOPP'] * ($param['PAY_SOPT'] / 100));
        }
    }

    /**
     *
     * Create by tk.kim 2017.09
     *
     * @param $param
     * @return mixed
     */
    private function getCalculateKeUserDefaultAdjust(&$param)
    {
        $param['PAY_CDDP'] = round($param['DRBP'] * ($param['PAY_CDDT'] / 100));
        $param['PAY_KDDP'] = round($param['DRBP'] * ($param['PAY_KDDT'] / 100));
        $param['PAY_DDDP'] = round($param['DRBP'] - $param['PAY_CDDP'] - $param['PAY_KDDP'] - $param['DVCDP'] - $param['DVCCP']);
//        if ($adjust_contract_detail['user_type'] == 'KET_01') $adjust_calculate_params['PAY_DDT'] = 0;

        $param['PAY_DDP'] = round($param['PAY_DDDP'] * ($param['PAY_DDT'] / 100));
        $param['TDRBP'] = ($param['user_type'] == 'KET_01') ? round($param['PAY_KDDP']) : $param['PAY_DDP'];
        if ($param['user_type'] == 'KET_01') {
            $param['PAY_DDT'] = 0;
            $param['RDC_TDRBP'] = $param['TDRBP'];
        }

        if ($param['user_type'] == 'KET_03') {
            $company_id = session('admin_login_info.company_id');
            $param['RDC_RT'] = $this->getLastReduceRate($company_id, $param['ref_contract_idx'], $param['ORC'], $param['YMDH']);
            $param['RDC_RT_MAX'] = $this->RDC_RT_MAX;
            if ($param['RDC_RT'] != null) {
                $param['RDC_TDRBP'] = round($param['TDRBP'] * (min([$param['RDC_RT'], $param['RDC_RT_MAX']]) / 100));
            } else {
                $param['RDC_TDRBP'] = $param['TDRBP'];
                $param['RDC_RT'] = 0;
            }
        }
    }

    /**
     *
     * Create by tk.kim 2017.09
     *
     * @param $company_id
     * @param $contract_idx
     * @param $reduce_order_qty
     * @param $ymdh
     * @return bool|float
     */
    private function getLastReduceRate($company_id, $contract_idx, $reduce_order_qty, $ymdh)
    {
        $last_reduce_rate = $this->getCache($company_id, '참여고객마지막전력수요 의무감축율', $contract_idx);
        if (is_null($last_reduce_rate)) {
            if (Carbon::parse(Carbon::parse($ymdh)->toDateString())->timestamp >= Carbon::parse('2017-08-31')->timestamp) {
                $ref_reduce_idx = $this->getDrLastReduce($contract_idx);
            } else {
                $last_reduce_rate = $this->getDrReduce($contract_idx, $ymdh);
                $this->createCache($company_id, '참여고객마지막전력수요 의무감축율', $contract_idx, $last_reduce_rate, 1);
                return $last_reduce_rate;
            }
            if (!is_null($ref_reduce_idx)) {
                $reduce_wattage_info = $this->getReduceInfoFromWattageByContractIdx($ref_reduce_idx, $contract_idx);
                $last_reduce_rate = $this->getReducePercentWithWattageInfo($reduce_wattage_info, $reduce_order_qty);
                $this->createCache($company_id, '참여고객마지막전력수요 의무감축율', $contract_idx, $last_reduce_rate, 1);
            } else {
                return null;
            }
        }
        return $last_reduce_rate;
    }

    /**
     *
     * Create by tk.kim 2017.09
     *
     * @param $reduce_wattage_info
     * @param $orc
     * @return float
     */
    private function getReducePercentWithWattageInfo($reduce_wattage_info, $orc)
    {
        try {
            return round(($reduce_wattage_info->reduce_qty / $orc) * 100, 2);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * 2017.09.01부터 참여고객의 마지막이벤트(감축시험 또는 전력수요 의무감축) 감축률 15분 기준
     * Create by tk.kim 2017.09
     *
     * @param $contract_idx
     * @return mixed
     */
    private function getDrLastReduce($contract_idx)
    {
        $reduce_contract = ReduceContract::select('_reduce_contract.ref_reduce_idx')
            ->leftJoin('_reduce', '_reduce_contract.ref_reduce_idx', '_reduce.idx')
            ->where('_reduce_contract.ref_contract_idx', $contract_idx)
            ->whereIn('_reduce.reduce_type', [0, 4])
            ->orderBy('_reduce.idx', 'DESC')
            ->first();
        if (!is_null($reduce_contract)) return $reduce_contract->ref_reduce_idx;
        return null;
    }

    /**
     * ~2017.08.31까지 참여고객의 해당월이벤트(감축시험 또는 전력수요 의무감축) 감축률
     * Create by tk.kim 2017.09
     * @param $contract_idx
     * @param $ymdh
     * @return mixed
     */
    private function getDrReduce($contract_idx, $ymdh)
    {
        $ym = Carbon::parse($ymdh)->format('Ym');
        $s_date = Carbon::parse($ym . '01')->timestamp;
        $e_date = Carbon::parse(Carbon::createFromTimestamp($s_date)->lastOfMonth()->format('Y-m-d 23:59:59'))->timestamp;
        $reduce_contracts = ReduceContract::select(['_reduce_contract.ref_reduce_idx', '_reduce_contract.reduce_order_qty'])
            ->leftJoin('_reduce', '_reduce_contract.ref_reduce_idx', '_reduce.idx')
            ->where('_reduce_contract.ref_contract_idx', $contract_idx)
            ->whereBetween('_reduce.start_date', [$s_date, $e_date])
            ->whereIn('_reduce.reduce_type', [0, 4])
            ->orderBy('_reduce.idx', 'DESC')
            ->get();
        $total_order_qty = 0;
        $total_qty = 0;
        foreach ($reduce_contracts as $reduce_contract) {
            $reduce_wattage_info = $this->getReduceInfoFromWattageByContractIdx($reduce_contract->ref_reduce_idx, $contract_idx);
            $total_order_qty += $reduce_contract->reduce_order_qty;
            $total_qty += $reduce_wattage_info->reduce_qty;
        }

        $total_rate = ($total_qty != 0 and $total_order_qty != 0) ? ($total_qty / $total_order_qty) * 100 : 0;
        if (!is_null($reduce_contracts)) return ($total_rate == 0) ? null : round($total_rate, 2);
        return null;
    }

    private function adjustEtcDetailUpdateOrCreate(array $where)
    {
        $ym = $where['YM'];
        $contractIdx = $where['ref_contract_idx'];
        $drIdx = $where['ref_dr_idx'];
        # EIMS-1230
        $m = Carbon::parse("{$ym}01")->format('m');
        $adjust_etc_rate = new AdjustEtcRate;
        $dr = Dr::select(['start_date', 'end_date'])->find($drIdx);
        $s_date = $ym . '01';
        if ($m == '11') {
            $s_date = $ym . '01';
            if ($ym == Carbon::parse($dr->start_date)->format('Ym')) $s_date = $ym . '25';
        }
        $contract_etc_rate = $adjust_etc_rate->where(['type' => 'Contract', 'c_idx' => $contractIdx, 'pay_type' => 'Won'])
            ->whereRaw("{$s_date} between start_date and end_date")
            ->get();

        foreach ($contract_etc_rate as $item) {
            $where = [
                'type' => $item->type,
                'ref_dr_idx' => $drIdx,
                'ref_etc_idx' => $item->idx,
                'c_idx' => $item->c_idx,
                'YM' => $ym,
            ];

            $insert = [
                'type' => $item->type,
                'ref_dr_idx' => $drIdx,
                'ref_etc_idx' => $item->idx,
                'c_idx' => $item->c_idx,
                'YM' => $ym,
                'start_date' => $item->start_date,
                'end_date' => $item->end_date,
                'title' => $item->title,
                'pay_method' => $item->pay_method,
                'pay_type' => $item->pay_type,
                'pay_value' => $item->pay_value,
                'created_id' => $item->created_id,
                'updated_id' => $item->updated_id,
                'created_at' => Carbon::now()
            ];
            AdjustEtcDetail::updateOrInsert($where, $insert);
        }
    }

    private function getAdjustEtcDetail($original_adjust, $configs, &$total, &$details)
    {
        $details['etc'][] = [
            'dr_name' => $original_adjust->dr_name,
            'contract_name' => $original_adjust->contract_name,
            'start_date' => $original_adjust->start_date,
            'end_date' => $original_adjust->end_date,
            'title' => $original_adjust->title,
            'pay_method' => findConfigValueName($configs['AdjustsEtcRateMethod'], $original_adjust->pay_method),
            'pay_type' => findConfigValueName($configs['AdjustsEtcRateType'], $original_adjust->pay_type),
            'pay_value' => $original_adjust->pay_value,
        ];
        $total['etc'] += $original_adjust->pay_value;
    }

    private function adjustDetailUpdateOrCreate(array $where, array $adjust_calculate_params, $model_class)
    {
        $hasOne = $model_class::where($where)->first();
        if (!is_null($hasOne)) {
            # update
            if ($hasOne->is_pay == 'Y') return 'Y';
            $adjust_calculate_params['updated_id'] = session('admin_login_info.code', 'SYSTEM');
            $adjust_calculate_params['updated_at'] = Carbon::now();
            $result = $model_class::where($where)->update($adjust_calculate_params);
        } else {
            # create
            $adjust_calculate_params['created_id'] = session('admin_login_info.code', 'SYSTEM');
            $adjust_calculate_params['created_at'] = Carbon::now();
            $result = $model_class::updateOrCreate($where, $adjust_calculate_params);
//            $result = $model_class::insert($adjust_calculate_params);
        }
        return $result;
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $month
     * @param $model_class
     * @param $drs
     * @return bool
     */
    private function getAdjustIsPayed($month, $model_class, $drs = null)
    {
        $hasOne = $model_class::where(['YM' => $month, 'is_pay' => 'Y']);
        if (!is_null($drs)) $hasOne = $hasOne->whereIn('ref_dr_idx', $drs);
        $hasOne = $hasOne->first();
        if (!is_null($hasOne)) return true;
        return false;
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $month
     * @param $model_class
     * @return bool
     */
    public function getAdjustIsNotNull($month, $model_class)
    {
        $hasOne = $model_class::where(['YM' => $month])->first();
        if (!is_null($hasOne)) return true;
        return false;
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param object $original_adjust
     * @return array
     */
    private function setContractAndUserDefault($original_adjust)
    {
        $return = [
            'dr_name' => $original_adjust->dr_name,
            'contract_name' => $original_adjust->contract_name,
            'orc' => floatval($original_adjust->ORC),
            'bp' => floatval($original_adjust->BP),
            'drbp' => floatval($original_adjust->DRBP),
            'pay_ddt' => floatval($original_adjust->PAY_DDT),
            'pay_ddp' => floatval($original_adjust->PAY_DDP),
        ];
        if (isset($original_adjust->user_name) and !empty($original_adjust->user_name)) {
            $return['user_name'] = $original_adjust->user_name;
            $return['user_code'] = $original_adjust->user_code;
        }
        return $return;
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param object $original_adjust
     * @return array
     */
    private function setContractAndUserDrBreak($original_adjust)
    {
        $return = [
            'dr_name' => $original_adjust->dr_name,
            'contract_name' => $original_adjust->contract_name,
            'orc' => floatval($original_adjust->ORC),
            'bp' => floatval($original_adjust->BP),
            'drbp' => floatval($original_adjust->DRBP),
            'tdrbp' => floatval($original_adjust->TDRBP),
            'mrt' => floatval($original_adjust->MRT),
            'rso' => floatval($original_adjust->RSO),
            'dr' => floatval($original_adjust->DR),
            'drd' => floatval($original_adjust->DRD),
            'bpcf' => floatval($original_adjust->BPCF),
            'df' => floatval($original_adjust->DF),
            'ibpc' => floatval($original_adjust->IBPC),
            'bpc' => floatval($original_adjust->BPC),
            'pay_dbt' => floatval($original_adjust->PAY_DBT),
            'pay_dbp' => floatval($original_adjust->PAY_DBP),
        ];
        if (isset($original_adjust->user_name) and !empty($original_adjust->user_name)) {
            $return['user_name'] = $original_adjust->user_name;
            $return['user_code'] = $original_adjust->user_code;
        }
        return $return;
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param object $original_adjust
     * @return array
     */
    private function setContractAndUserSrBreak($original_adjust)
    {
        $event_type = ($original_adjust->sr_ref_reduce_idx != 0) ? '전력수요 의무감축/계획감축' : '계획감축';
        $end_h = Carbon::parse("2017-01-01 {$original_adjust->start_h}:00:00")->addHour()->format('H');
        $reduce_time = "{$original_adjust->start_h}~{$end_h}시";

        $return = [
            'event_type' => $event_type,
            'dr_name' => $original_adjust->dr_name,
            'contract_name' => $original_adjust->contract_name,
            'start_date' => Carbon::parse($original_adjust->YMDH)->toDateString(),
            'reduce_time' => $reduce_time,
            'duration' => $original_adjust->duration,
            'orc' => $original_adjust->ORC,
            'rso' => $original_adjust->RSO,
            'xrsof' => $original_adjust->XRSOF,
            'dcbl' => $original_adjust->DCBL,
            'me' => $original_adjust->ME,
            'dr' => $original_adjust->DR,
            'smp' => $original_adjust->SMP,
            'df' => $original_adjust->DF,
            'scbl' => $original_adjust->SCBL,
            'sr' => $original_adjust->SR,
            'pssr' => $original_adjust->PSSR,
            'drrp' => $original_adjust->DRRP,
            'ppcf' => $original_adjust->PPCF,
            'ppc' => $original_adjust->PPC,
            'pay_sbt' => $original_adjust->PAY_SBT,
            'pay_sbp' => $original_adjust->PAY_SBP,
        ];
        if (isset($original_adjust->user_name) and !empty($original_adjust->user_name)) {
            $return['user_name'] = $original_adjust->user_name;
            $return['user_code'] = $original_adjust->user_code;
        }
        return $return;
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $original_adjust
     * @return array
     */
    private function setContractAndUserDrProfit($original_adjust)
    {
        $event_type = ($original_adjust->sr_ref_reduce_idx != 0) ? '전력수요 의무감축/계획감축' : '전력수요 의무감축';
        $end_h = Carbon::parse("2017-01-01 {$original_adjust->start_h}:00:00")->addHour()->format('H');
        $reduce_time = "{$original_adjust->start_h}~{$end_h}시";

        $return = [
            'event_type' => $event_type,
            'dr_name' => $original_adjust->dr_name,
            'start_date' => Carbon::parse($original_adjust->YMDH)->toDateString(),
            'reduce_time' => $reduce_time,
            'duration' => $original_adjust->duration,
            'contract_name' => $original_adjust->contract_name,
            'orc' => $original_adjust->ORC,
            'rso' => $original_adjust->RSO,
            'xrsof' => $original_adjust->XRSOF,
            'dcbl' => $original_adjust->DCBL,
            'me' => $original_adjust->ME,
            'dr' => $original_adjust->DR,
            'smp' => $original_adjust->SMP,
            'df' => $original_adjust->DF,
            'drp' => $original_adjust->DRP,
            'pay_dpt' => $original_adjust->PAY_DPT,
            'pay_dpp' => $original_adjust->PAY_DPP
        ];
        if (isset($original_adjust->user_name) and !empty($original_adjust->user_name)) {
            $return['user_name'] = $original_adjust->user_name;
            $return['user_code'] = $original_adjust->user_code;
        }
        return $return;
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $original_adjust
     * @return array
     */
    private function setContractAndUserSrProfit($original_adjust)
    {
        $event_type = ($original_adjust->sr_ref_reduce_idx != 0) ? '전력수요 의무감축/계획감축' : '계획감축';
        $end_h = Carbon::parse("2017-01-01 {$original_adjust->start_h}:00:00")->addHour()->format('H');
        $reduce_time = "{$original_adjust->start_h}~{$end_h}시";

        $return = [
            'event_type' => $event_type,
            'dr_name' => $original_adjust->dr_name,
            'start_date' => $original_adjust->YMDH,
            'reduce_time' => $reduce_time,
            'duration' => $original_adjust->duration,
            'contract_name' => $original_adjust->contract_name,
            'orc' => $original_adjust->ORC,
            'rso' => $original_adjust->RSO,
            'xrsof' => $original_adjust->XRSOF,
            'dcbl' => $original_adjust->DCBL,
            'me' => $original_adjust->ME,
            'dr' => $original_adjust->DR,
            'smp' => $original_adjust->SMP,
            'df' => $original_adjust->DF,
            'scbl' => $original_adjust->SCBL,
            'sr' => $original_adjust->SR,
            'pssr' => $original_adjust->PSSR,
            'slrp' => $original_adjust->SLRP,
            'drrp' => $original_adjust->DRRP,
            'xdresmp' => $original_adjust->PAY_SOPP,
            'pay_spt' => $original_adjust->PAY_SPT,
            'pay_spp' => $original_adjust->PAY_SPP
        ];
        if (isset($original_adjust->user_name) and !empty($original_adjust->user_name)) {
            $return['user_name'] = $original_adjust->user_name;
            $return['user_code'] = $original_adjust->user_code;
        }

        return $return;
    }

    /**
     *
     * Create by tk.kim 2017.09
     *
     * @param $original_adjust
     * @return array
     */
    private function setKeUserDefault($original_adjust)
    {
        $return = [
            'user_type' => $original_adjust->user_type,
            'dr_name' => $original_adjust->dr_name,
            'contract_name' => $original_adjust->contract_name,
            'orc' => $original_adjust->ORC,
            'bp' => $original_adjust->BP,
            'drbp' => $original_adjust->DRBP,
            'pay_cddt' => $original_adjust->PAY_CDDT,
            'pay_cddp' => $original_adjust->PAY_CDDP,
            'pay_kddt' => $original_adjust->PAY_KDDT,
            'pay_kddp' => $original_adjust->PAY_KDDP,
            'dvcdp' => $original_adjust->DVCDP,
            'dvccp' => $original_adjust->DVCCP,
            'pay_dddp' => $original_adjust->PAY_DDDP, # 수요사업자 기본정산금
            'pay_ddt' => $original_adjust->PAY_DDT, # 관리사원 지급율
            'pay_ddp' => $original_adjust->PAY_DDP, # 관리사원 지급액
            'pay_ddp' => $original_adjust->PAY_DDP, # 관리사원 지급액
            'tdrbp' => $original_adjust->TDRBP, # 최종 기본정산금
            'rdc_rt' => $original_adjust->RDC_RT, # 감축률 (급전,감축시험)
            'rdc_rt_max' => $original_adjust->RDC_RT_MAX, # 상한 비율
            'rdc_tdrbp' => $original_adjust->RDC_TDRBP # 감축률적용 관리사원지급액
        ];
        if (isset($original_adjust->user_name) and !empty($original_adjust->user_name)) {
            $return['user_name'] = $original_adjust->user_name;
            $return['user_code'] = $original_adjust->user_code;
        }
        return $return;
    }

    /**
     *
     * Create by tk.kim 2017.09
     *
     * @param $original_adjust
     * @return array
     */
    private function setKeUserDrProfit($original_adjust)
    {
        $event_type = ($original_adjust->sr_ref_reduce_idx != 0) ? '전력수요 의무감축/계획감축' : '전력수요 의무감축';
        $end_h = Carbon::parse("2017-01-01 {$original_adjust->start_h}:00:00")->addHour()->format('H');
        $reduce_time = "{$original_adjust->start_h}~{$end_h}시";

        $return = [
            'event_type' => $event_type,
            'user_type' => $original_adjust->user_type,
            'dr_name' => $original_adjust->dr_name,
            'start_date' => Carbon::parse($original_adjust->YMDH)->toDateString(),
            'reduce_time' => $reduce_time,
            'duration' => $original_adjust->duration,
            'contract_name' => $original_adjust->contract_name,
            'orc' => $original_adjust->ORC,
            'rso' => $original_adjust->RSO,
            'xrsof' => $original_adjust->XRSOF,
            'dcbl' => $original_adjust->DCBL,
            'me' => $original_adjust->ME,
            'dr' => $original_adjust->DR,
            'df' => $original_adjust->DF,
            'drp' => $original_adjust->DRP,
            'pay_cdpt' => $original_adjust->PAY_CDPT,
            'pay_cdpp' => $original_adjust->PAY_CDPP,
            'pay_ddpp' => $original_adjust->PAY_DDPP,
            'pay_dpt' => $original_adjust->PAY_DPT,
            'pay_dpp' => $original_adjust->PAY_DPP,
        ];
        if (isset($original_adjust->user_name) and !empty($original_adjust->user_name)) {
            $return['user_name'] = $original_adjust->user_name;
            $return['user_code'] = $original_adjust->user_code;
        }
        return $return;
    }

    /**
     *
     * Create by tk.kim 2017.09
     *
     * @param $original_adjust
     * @return array
     */
    private function setKeUserSrProfit($original_adjust)
    {
        $event_type = ($original_adjust->sr_ref_reduce_idx != 0) ? '전력수요 의무감축/계획감축' : '계획감축';
        $end_h = Carbon::parse("2017-01-01 {$original_adjust->start_h}:00:00")->addHour()->format('H');
        $reduce_time = "{$original_adjust->start_h}~{$end_h}시";

        $return = [
            'event_type' => $event_type,
            'user_type' => $original_adjust->user_type,
            'dr_name' => $original_adjust->dr_name,
            'start_date' => $original_adjust->YMDH,
            'reduce_time' => $reduce_time,
            'duration' => $original_adjust->duration,
            'contract_name' => $original_adjust->contract_name,
            'me' => $original_adjust->ME,
            'smp' => $original_adjust->SMP,
            'scbl' => $original_adjust->SCBL,
            'sr' => $original_adjust->SR,
            'pssr' => $original_adjust->PSSR,
            'slrp' => $original_adjust->SLRP,
            'drrp' => $original_adjust->DRRP,
            'xdresmp' => $original_adjust->XDRESMP,
            'pay_cspt' => $original_adjust->PAY_CSPT,
            'pay_cspp' => $original_adjust->PAY_CSPP,
            'pay_dspp' => $original_adjust->PAY_DSPP,
            'pay_spt' => $original_adjust->PAY_SPT,
            'pay_spp' => $original_adjust->PAY_SPP,
            'pay_csopt' => $original_adjust->PAY_CSOPT,
            'pay_csopp' => $original_adjust->PAY_CSOPP,
            'pay_dsopp' => $original_adjust->PAY_DSOPP,
            'pay_sopt' => $original_adjust->PAY_SOPT,
            'pay_sopp' => $original_adjust->PAY_SOPP,
        ];
        if (isset($original_adjust->user_name) and !empty($original_adjust->user_name)) {
            $return['user_name'] = $original_adjust->user_name;
            $return['user_code'] = $original_adjust->user_code;
        }
        return $return;
    }

    /**
     *
     * Create by tk.kim 2017.08
     * @param object $original_adjust
     * @return array
     */
    private function setDrAdjustForPeriod($original_adjust)
    {
        $is_pay = ($original_adjust->is_pay == 'Y') ? '확정' : '미확정';
        $total = round(($original_adjust->DRBP + $original_adjust->sum_drp + $original_adjust->sum_slrp) - $original_adjust->sum_bpc - $original_adjust->sum_ppc, 2);
        # DRP 급전실적, DRBP 기본정산금, BPC 급전위약금
        #
        # SLRP 계획실적, XDRESMP 계획추가실적, PPC 계획위약금

        return [
            'ym' => $original_adjust->YM,
            'is_pay' => $is_pay,
            'dr_name' => $original_adjust->dr_name,
            'drp' => $original_adjust->sum_drp,
            'drbp' => $original_adjust->DRBP,
            'slrp' => $original_adjust->sum_slrp,
            'xdresmp' => $original_adjust->sum_xdresmp,
            'bpc' => $original_adjust->sum_bpc,
            'ppc' => $original_adjust->sum_ppc,
            'total' => $total
        ];
    }

    /**
     *
     * Create by tk.kim 2017.08
     * @param object $original_adjust
     * @return array
     */
    private function setContractAdjustForPeriod($original_adjust)
    {
        $is_pay = ($original_adjust->is_pay == 'Y') ? '확정' : '미확정';
        if (is_null($original_adjust->sum_erp)) $original_adjust->sum_erp = 0;
        $total = round(($original_adjust->PAY_DDP + $original_adjust->sum_drp + $original_adjust->sum_slrp) - $original_adjust->sum_bpc - $original_adjust->sum_ppc + $original_adjust->sum_erp);
        # DRP 급전실적, DRBP 기본정산금, BPC 급전위약금
        #
        # SLRP 계획실적, XDRESMP 계획추가실적, PPC 계획위약금

        return [
            'ym' => $original_adjust->YM,
            'is_pay' => $is_pay,
            'code_is_pay' => $original_adjust->is_pay,
            'dr_name' => $original_adjust->dr_name,
            'contract_name' => $original_adjust->contract_name,
            'contract_idx' => $original_adjust->contract_idx,
            'drp' => $original_adjust->sum_drp,
            'drbp' => $original_adjust->PAY_DDP,
            'slrp' => $original_adjust->sum_slrp,
            'xdresmp' => $original_adjust->sum_xdresmp,
            'bpc' => $original_adjust->sum_bpc,
            'ppc' => $original_adjust->sum_ppc,
            'etc' => $original_adjust->sum_erp,
            'total' => $total
        ];
    }

    /**
     *
     * Create by tk.kim 2017.08
     * @param object $original_adjust
     * @return array
     */
    private function setUserAdjustForPeriod($original_adjust)
    {
        $is_pay = ($original_adjust->is_pay == 'Y') ? '확정' : '미확정';
        $total = round(($original_adjust->DRBP + $original_adjust->sum_drp + $original_adjust->sum_slrp) - $original_adjust->sum_bpc - $original_adjust->sum_ppc, 2);
        # DRP 급전실적, DRBP 기본정산금, BPC 급전위약금
        #
        # SLRP 계획실적, XDRESMP 계획추가실적, PPC 계획위약금

        return [
            'ym' => $original_adjust->YM,
            'is_pay' => $is_pay,
            'user_name' => $original_adjust->user_name,
            'user_code' => $original_adjust->user_code,
            'drp' => $original_adjust->sum_drp,
            'drbp' => $original_adjust->DRBP,
            'slrp' => $original_adjust->sum_slrp,
            'xdresmp' => $original_adjust->sum_xdresmp,
            'bpc' => $original_adjust->sum_bpc,
            'ppc' => $original_adjust->sum_ppc,
            'total' => $total
        ];
    }

    /**
     *
     * Create by tk.kim 2017.08
     * @param object $original_adjust
     * @return array
     */
    private function setKeUserAdjustForPeriod($original_adjust)
    {
        $is_pay = ($original_adjust->is_pay == 'Y') ? '확정' : '미확정';
        $total = round($original_adjust->rdc_tdrbp + $original_adjust->sum_dpp + $original_adjust->sum_spp + $original_adjust->sum_sopp, 2);
        # DRP 급전실적, DRBP 기본정산금, BPC 급전위약금
        #
        # SLRP 계획실적, XDRESMP 계획추가실적, PPC 계획위약금
        return [
            'ym' => $original_adjust->YM,
            'is_pay' => $is_pay,
            'user_name' => $original_adjust->user_name,
            'user_code' => $original_adjust->user_code,
            'rdc_tdrbp' => $original_adjust->rdc_tdrbp,
            'dpp' => $original_adjust->sum_dpp,
            'spp' => $original_adjust->sum_spp,
            'sopp' => $original_adjust->sum_sopp,
            'total' => $total
        ];
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $original_adjust
     * @param $dr_default
     * @param $dr_profit
     * @param $contract_default
     * @param $contract_profit
     * @param $user_default
     * @param $user_profit
     * @param $contract_etc
     * @return array
     */
    private function setCollectiveAdjustsForPeriod($original_adjust, $dr_default, $dr_profit, $contract_default, $contract_profit, $user_default, $user_profit, $contract_etc)
    {
        $default = (object)['DRBP' => 0, 'BPC' => 0, 'is_pay' => 'N'];
        $profit = (object)['DRP' => 0, 'SLRP' => 0, 'XDRESMP' => 0, 'PPC' => 0];

        $dr_default = (is_null($dr_default->is_pay)) ? $default : $dr_default;
        $dr_profit = (is_null($dr_profit)) ? $profit : $dr_profit;
        $contract_default = (is_null($contract_default->is_pay)) ? $default : $contract_default;
        $contract_profit = (is_null($contract_profit)) ? $profit : $contract_profit;
        $user_default = (is_null($user_default->is_pay)) ? $default : $user_default;
        $user_profit = (is_null($user_profit)) ? $profit : $user_profit;

        $calculate_dr = $this->calculate_adjust($dr_default, $dr_profit);
        $calculate_contract = $this->calculate_adjust($contract_default, $contract_profit);
        $calculate_user = $this->calculate_adjust($user_default, $user_profit);

        if (is_null($contract_etc) or is_null($contract_etc->calculate_contract_etc)) $contract_etc->calculate_contract_etc = 0;
        $calculate_contract_sum = $calculate_contract + $contract_etc->calculate_contract_etc;
        $calculate_user_sum = $calculate_user + 0;

        $total = round($calculate_dr - $calculate_contract_sum - $calculate_user_sum, 2);
        $percent = ($total != 0 and $calculate_dr != 0) ? round($total / $calculate_dr, 2) * 100 : 0;
        return [
            'ym' => $original_adjust->YM,
            'dr_name' => $original_adjust->dr_name,
            'dr_reduce_register_capacity' => $original_adjust->dr_reduce_register_capacity,
            'dr_is_pay' => ($dr_default->is_pay == 'Y') ? '확정' : '미확정',
            'calculate_dr' => $calculate_dr,
            'contract_is_pay' => ($contract_default->is_pay == 'Y') ? '확정' : '미확정',
            'calculate_contract' => $calculate_contract,
            'calculate_contract_etc' => $contract_etc->calculate_contract_etc,
            'calculate_contract_sum' => $calculate_contract_sum,
            'user_is_pay' => ($user_default->is_pay == 'Y') ? '확정' : '미확정',
            'calculate_user' => $calculate_user,
            'calculate_user_etc' => 0,
            'calculate_user_sum' => $calculate_user_sum,
            'total' => $total,
            'percent' => $percent
        ];
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $default
     * @param $profit
     * @return mixed
     */
    private function calculate_adjust($default, $profit)
    {
        try {
            # DRBP - BPC + DRP + SLRP + XDRESMP - PPC
            return round(($default->DRBP + $profit->DRP + $profit->SLRP + $profit->XDRESMP - $profit->PPC - $default->BPC), 2);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $original_adjust
     * @return int
     */
    private function setCalculateCollectiveReport($original_adjust)
    {
        $etc = 0;
        if (isset($original_adjust->sum_erp)) $etc = $original_adjust->sum_erp;
        try {
            return round($original_adjust->PAY_DDP + $original_adjust->PAY_DPP + $etc + $original_adjust->PAY_SPP + $original_adjust->PAY_SOPP - $original_adjust->PAY_DBP - $original_adjust->PAY_SBP, 2);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $original_adjust
     * @param $total
     * @param $details_total
     */
    private function setAdjustTotalForMonth($original_adjust, &$total, $details_total)
    {
        $total['is_pay'] = ($original_adjust->is_pay == 'Y') ? '확정' : '미확정';
        $total['default'] = $details_total['default']['drbp'];
        $total['profit'] = $details_total['dr_profit']['drp'] + $details_total['sr_profit']['slrp'] + $details_total['sr_profit']['xdresmp'];
        $total['break'] = $details_total['dr_break']['bpc'] + $details_total['sr_break']['ppc'];
        $total['total'] = $total['default'] + $total['profit'] - $total['break'];
    }

    /**
     *
     * Create by tk.kim 2018.02
     */
    private function setAdjustUserTotalForMonth($original_adjust, &$total, $details_total)
    {
        $total['is_pay'] = ($original_adjust->is_pay == 'Y') ? '확정' : '미확정';
        $total['default'] = $details_total['default']['pay_ddp'];
        $total['profit'] = $details_total['dr_profit']['pay_dpp'] + $details_total['sr_profit']['pay_spp'] + $details_total['sr_profit']['xdresmp'];
        $total['break'] = $details_total['dr_break']['pay_dbp'] + $details_total['sr_break']['ppc'];
        $total['total'] = $total['default'] + $total['profit'] - $total['break'];
    }

    /**
     * 참여고객 월별 정산금 총계 계산
     * Create by tk.kim 2018.02
     *
     * @param $original_adjust
     * @param $total
     * @param $details_total
     */
    private function setAdjustContractTotalForMonth($original_adjust, &$total, $details_total)
    {
        $details_total['dr_profit']['pay_dpp'] = ($details_total['dr_profit']['pay_dpp'] != 0) ? round($details_total['dr_profit']['pay_dpp']) : 0;

        $total['is_pay'] = ($original_adjust->is_pay == 'Y') ? '확정' : '미확정';
        $total['default'] = $details_total['default']['pay_ddp'];
        $total['profit'] = $details_total['dr_profit']['pay_dpp'] + $details_total['sr_profit']['pay_spp'] + $details_total['sr_profit']['xdresmp'];
        $total['break'] = $details_total['dr_break']['pay_dbp'] + $details_total['sr_break']['pay_sbp'];
        $total['total'] = $total['default'] + $total['profit'] - $total['break'];
        if (isset($total['etc'])) $total['total'] += $total['etc'];
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $original_adjust
     * @param $total
     * @param $details_total
     */
    private function setKeUserAdjustTotalForMonth($original_adjust, &$total, $details_total)
    {
        $total['is_pay'] = ($original_adjust->is_pay == 'Y') ? '확정' : '미확정';
        $total['default'] = $details_total['default']['rdc_tdrbp'];
        $total['profit'] = $details_total['dr_profit']['pay_dpp'] + $details_total['sr_profit']['pay_spp'] + $details_total['sr_profit']['pay_sopp'];
        $total['total'] = $total['default'] + $total['profit'];
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $details_total
     * @param $total
     */
    private function getAdjustTotalForPeriod($details_total, &$total)
    {
        $total['default'] = $details_total['drbp'];
        $total['profit'] = $details_total['drp'] + $details_total['slrp'] + $details_total['xdresmp'];
        $total['over_profit'] = 0;
        $total['break'] = $details_total['bpc'] + $details_total['ppc'];
        $total['total'] = $total['default'] + $total['profit'] + $total['over_profit'] - $total['break'];
        if (isset($details_total['etc'])) {
            $total['etc'] = $details_total['etc'];
            $total['total'] += $details_total['etc'];
        }
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $details_total
     * @param $total
     */
    private function getAdjustKeUserTotalForPeriod($details_total, &$total)
    {
        $total['default'] = $details_total['rdc_tdrbp'];
        $total['profit'] = $details_total['dpp'] + $details_total['spp'] + $details_total['sopp'];
        $total['over_profit'] = 0;
        $total['total'] = $total['default'] + $total['profit'] + $total['over_profit'];
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $original_adjust
     * @return array
     */
    private function setDrDefault($original_adjust)
    {
        return [
            'dr_name' => $original_adjust->dr_name,
            'orc' => $original_adjust->ORC,
            'bp' => $original_adjust->BP,
            'drbp' => $original_adjust->DRBP,
        ];
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $original_adjust
     * @return array
     */
    private function setDrProfit($original_adjust)
    {
        $event_type = ($original_adjust->sr_ref_reduce_idx != 0) ? '전력수요 의무감축/계획감축' : '전력수요 의무감축';
        $end_h = Carbon::parse("2017-01-01 {$original_adjust->start_h}:00:00")->addHour()->format('H');
        $reduce_time = "{$original_adjust->start_h}~{$end_h}시";

        return [
            'event_type' => $event_type,
            'dr_name' => $original_adjust->dr_name,
            'contract_name' => $original_adjust->contract_name,
            'start_date' => Carbon::parse($original_adjust->YMDH)->toDateString(),
            'reduce_time' => $reduce_time,
            'duration' => $original_adjust->duration,
            'orc' => $original_adjust->ORC,
            'rso' => $original_adjust->RSO,
            'xrsof' => $original_adjust->XRSOF,
            'dcbl' => $original_adjust->DCBL,
            'me' => $original_adjust->ME,
            'dr' => $original_adjust->DR,
            'smp' => $original_adjust->SMP,
            'df' => $original_adjust->DF,
            'drp' => $original_adjust->DRP
        ];
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $original_adjust
     * @return array
     */
    private function setSrProfit($original_adjust)
    {
        $event_type = ($original_adjust->sr_ref_reduce_idx != 0) ? '전력수요 의무감축/계획감축' : '계획감축';
        $end_h = Carbon::parse("2017-01-01 {$original_adjust->start_h}:00:00")->addHour()->format('H');
        $reduce_time = "{$original_adjust->start_h}~{$end_h}시";

        return [
            'event_type' => $event_type,
            'dr_name' => $original_adjust->dr_name,
            'contract_name' => $original_adjust->contract_name,
            'start_date' => Carbon::parse($original_adjust->YMDH)->toDateString(),
            'reduce_time' => $reduce_time,
            'duration' => $original_adjust->duration,
            'orc' => $original_adjust->ORC,
            'rso' => $original_adjust->RSO,
            'xrsof' => $original_adjust->XRSOF,
            'dcbl' => $original_adjust->DCBL,
            'me' => $original_adjust->ME,
            'dr' => $original_adjust->DR,
            'smp' => $original_adjust->SMP,
            'df' => $original_adjust->DF,
            'scbl' => $original_adjust->SCBL,
            'sr' => $original_adjust->SR,
            'pssr' => $original_adjust->PSSR,
            'slrp' => $original_adjust->SLRP,
            'drrp' => $original_adjust->DRRP,
            'xdresmp' => $original_adjust->XDRESMP
        ];
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $original_adjust
     * @return array
     */
    private function setDrBreak($original_adjust)
    {
        return [
            'dr_name' => $original_adjust->dr_name,
            'orc' => $original_adjust->ORC,
            'bp' => $original_adjust->BP,
            'drbp' => $original_adjust->DRBP,
            'tdrbp_value' => $original_adjust->TDRBP_VALUE,
            'tdrbp' => $original_adjust->TDRBP,
            'mrt' => $original_adjust->MRT,
            'rso' => $original_adjust->RSO,
            'dr' => $original_adjust->DR,
            'drd' => $original_adjust->DRD,
            'bpcf' => $original_adjust->BPCF,
            'df' => $original_adjust->DF,
            'ibpc' => $original_adjust->IBPC,
            'bpc' => $original_adjust->BPC,
        ];
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $original_adjust
     * @return array
     */
    private function setSrBreak($original_adjust)
    {
        $event_type = ($original_adjust->sr_ref_reduce_idx != 0) ? '전력수요 의무감축/계획감축' : '계획감축';
        $end_h = Carbon::parse("2017-01-01 {$original_adjust->start_h}:00:00")->addHour()->format('H');
        $reduce_time = "{$original_adjust->start_h}~{$end_h}시";

        return [
            'event_type' => $event_type,
            'dr_name' => $original_adjust->dr_name,
            'start_date' => Carbon::parse($original_adjust->YMDH)->toDateString(),
            'reduce_time' => $reduce_time,
            'duration' => $original_adjust->duration,
            'orc' => $original_adjust->ORC,
            'rso' => $original_adjust->RSO,
            'xrsof' => $original_adjust->XRSOF,
            'dcbl' => $original_adjust->DCBL,
            'me' => $original_adjust->ME,
            'dr' => $original_adjust->DR,
            'smp' => $original_adjust->SMP,
            'df' => $original_adjust->DF,
            'scbl' => $original_adjust->SCBL,
            'sr' => $original_adjust->SR,
            'pssr' => $original_adjust->PSSR,
            'drrp' => $original_adjust->DRRP,
            'ppcf' => $original_adjust->PPCF,
            'ppc' => $original_adjust->PPC,
        ];
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $ym
     * @param $idx dr idx
     * @return mixed
     */
    public function getCacheBPbyYm($ym, $idx)
    {
        # EIMS-1230
        $bp = $this->getCommonCache('월별_BP', [$ym, $idx]);
        if (is_null($bp)) {
            $reduce_price_unit = new ReducePriceUnit;
            $reduce_price_unit = $reduce_price_unit->where('ym', $ym);
            $m = Carbon::parse("{$ym}01")->format('m');
            if ($m == '11') {
                $s_date = $ym . '01';
                $e_date = $ym . '24';
                $dr = Dr::select(['start_date', 'end_date'])->find($idx);
                if ($ym == Carbon::parse($dr->start_date)->format('Ym')) {
                    $s_date = $ym . '25';
                    $e_date = Carbon::parse($ym . '01')->lastOfMonth()->format('Ymd');
                }
                $reduce_price_unit = $reduce_price_unit->whereBetween('ymd', [$s_date, $e_date]);
            }
            $bp = $reduce_price_unit->sum('price');
            $bp = round(floatval($bp), 2);
            $this->createCommonCache('월별_BP', [$ym, $idx], $bp, 1);
        }
        return $bp;
    }

    /**
     *
     * Create by tk.kim 2017.08
     *
     * @param $idx
     * @return mixed
     */
    public function getCacheDrBPbyDrIdx($idx)
    {
        $drbp = $this->getCommonCache('자원기간별_BP', $idx);
        if (is_null($drbp)) {
            $dr = Dr::withTrashed()->select(['start_date', 'end_date'])->find($idx);
            $s_date = Carbon::parse($dr->start_date)->format('Ymd');
            $e_date = Carbon::parse($dr->end_date)->format('Ymd');
            $drbp = ReducePriceUnit::whereBetween('ymd', [$s_date, $e_date])->sum('price');
            $drbp = round(floatval($drbp));
            $this->createCommonCache('자원기간별_BP', $idx, $drbp, 1);
        }
        return $drbp;
    }

    /**
     *
     * Create by tk.kim 2017.09
     *
     * @param $datetime
     * @return bool
     */
    public function isTodayWithTimestamp($datetime)
    {
        return (Carbon::createFromTimestamp($datetime)->toDateString() == Carbon::today()->toDateString());
    }

    /**
     *
     * Create by tk.kim 2017.09
     *
     * @param $command
     * @param $month
     * @return array
     */
    public function adjustQueueHasOne($command, $month)
    {
        $hasOne = Job::where('payload', 'like', "%{$command}%")
            ->where('payload', 'like', "%{$month}%")
            ->first();

        if (is_null($hasOne)) return ['code' => 400, 'message' => '해당월 정산금 ‘재계산’중입니다. ‘재계산’에 약 5분정도 시간이 소요됩니다. ', 'is_continue' => true];
        if (!is_null($hasOne) and $hasOne->attempts == 1) return ['code' => 400, 'message' => '해당월에 정산 ‘재계산‘이 진행중입니다. 잠시 후 다시 시도해주세요. ', 'is_continue' => false];
        return ['code' => 400, 'message' => '이미 ‘재계산‘ 요청이 접수되었습니다.', 'is_continue' => false];
    }

    public function getReducePercent($reduce)
    {
        if (gettype($reduce) == 'object') {
            if (!empty($reduce) && $reduce->reduce_order_qty != 0 && $reduce->reduce_qty != 0) {
                return round(($reduce->reduce_qty / $reduce->reduce_order_qty) * 100, 2);
            }
        } else {
            if (!empty($reduce) && $reduce['reduce_order_qty'] != 0 && $reduce['reduce_qty'] != 0) {
                return round(($reduce['reduce_qty'] / $reduce['reduce_order_qty']) * 100, 2);
            }
        }
        return 0;
    }

    /**
     *
     * Create by tk.kim 2017.10
     *
     * @param $include
     * @param $reduce
     */
    private function setReduceDrReportsIncludeData(&$include, $reduce)
    {
        $include['cnt'] += 1;
        $include['m'] += $reduce->duration;
        $include['reduce_order_qty'] += $reduce->reduce_order_qty;
        $include['reduce_qty'] += $reduce->getAttribute('reduce_qty');
    }

    /**
     *
     * Create by tk.kim 2017.10
     *
     * @param $report
     */
    private function setReduceDrReportsRateData(&$report)
    {
        $report['rate'] = $this->getReducePercent($report);
    }

    /**
     *
     * Create by tk.kim 2017.10
     * @param $contract_idx
     * @param $user_idx
     * @param $s_date
     * @param $e_date
     * @param $after_30day
     * @param $yesterday
     * @param $reports
     * @param $join_dr
     * @param $njoin_dr
     * @return array
     */
    public function getReduceDrReportsData($contract_idx,
                                           $user_idx,
                                           $s_date,
                                           $e_date,
                                           $after_30day,
                                           $yesterday,
                                           $reports,
                                           $join_dr,
                                           $njoin_dr)
    {
        $totals = [
            'include' => [
                'reduce_order_qty' => 0,
                'reduce_qty' => 0,
                'rate' => 0,
            ],
            'ninclude' => [
                'reduce_order_qty' => 0,
                'reduce_qty' => 0,
                'rate' => 0,
            ],
            'cbl' => [
                '17_cbl' => 0,
                '18_cbl' => 0,
                '19_cbl' => 0,
            ],
            'capacity' => 0
        ];
        $company_id = $this->getLoginCompanyId();
        if ($user_idx === '0' && $contract_idx === '0') {
            $contract_idx = $this->getPermissionContractsIdx();
        } else {
            if (in_array($company_id, $this->KE_ADJUST_COMPANYS)) {
                $contract_idx = AdjustKeUserRate::select('c_idx as idx')
                    ->whereIn('c_idx', $contract_idx);
                if ($user_idx != '0') $contract_idx->where('u_idx', $user_idx);
                $contract_idx = $contract_idx->get();
            } else {
                $contract_idx = AdjustDefaultRate::select('c_idx as idx')
                    ->whereIn('c_idx', $contract_idx);
                if ($user_idx != '0') $contract_idx->where('u_idx', $user_idx);
                $contract_idx = $contract_idx->get();
            }
        }

        $contracts = Contract::with('NowJoinDr', 'NextJoinDr')->distinct()->whereIn('idx', $contract_idx)->get();
        $include = [
            'cnt' => 0,
            'm' => 0,
            'reduce_order_qty' => 0,
            'reduce_qty' => 0,
            'rate' => 0,
        ];

        $reduceTypes = $this->getSelectConfigOptions('ReduceType');

        /*$today_s_date = Carbon::today()->timestamp;
        $today_e_date = Carbon::tomorrow()->timestamp;
        $get_today_event = Reduce::leftJoin('_dr', '_dr.id', '=', '_reduce.ref_dr_id')
            ->where('_dr.company_id', $company_id)
            ->whereIn('_reduce.reduce_type', ['0', '2', '4'])
            ->whereBetween('_reduce.start_date', [$today_s_date, $today_e_date])
            ->count();
        $today_have_event = ($get_today_event == 0) ? false : true;*/
        foreach ($contracts as $contract) {
//            $cacheIsNull = false;
            $report = [];
            /*if (! $today_have_event) {
                $cacheTotals = $this->getCache($company_id, '참여고객전력수요 의무감축이력총계', $contract->idx);
                $cacheContract = $this->getCache($company_id, '참여고객전력수요 의무감축이력', $contract->idx);
                if (!is_null($cacheContract)) {
                    $reports[] = $cacheContract;
                    $totals = $cacheTotals;
                    continue;
                }
                $cacheIsNull = true;
            }*/

            $report['contract_name'] = $contract->name;
            $report['now_dr_idx'] = (!is_null($contract['NowJoinDr'])) ? $contract['NowJoinDr']->idx : '';
            $report['now_dr'] = (!is_null($contract['NowJoinDr'])) ? $contract['NowJoinDr']->name : '';
            $report['next_dr_idx'] = (!is_null($contract['NextJoinDr'])) ? $contract['NextJoinDr']->idx : '';
            $report['next_dr'] = (!is_null($contract['NextJoinDr'])) ? $contract['NextJoinDr']->name : '';
            $report['users'] = _User::select(['name', 'mobile'])->whereIn('idx', $this->getUserIdxByContractIdx($contract->idx))->get();
            $report['capacity_kepco'] = $contract->capacity_kepco;
            $report['capacity'] = $contract->capacity;
            $report['include'] = $include;
            $report['ninclude'] = $include;
            $report['s_date'] = Carbon::createFromTimestamp($s_date)->toDateString();
            $report['e_date'] = Carbon::createFromTimestamp($e_date)->toDateString();

            if ($join_dr > 0) {
                if ($report['now_dr_idx'] != $join_dr) continue;
            } else if ($join_dr === '-1') {
                if ($report['now_dr_idx'] != '') continue;
            }

            if ($njoin_dr > 0) {
                if ($report['next_dr_idx'] != $njoin_dr) continue;
            } else if ($njoin_dr === '-1') {
                if ($report['next_dr_idx'] != '') continue;
            }

            $reduces = Reduce::select(
                [
                    '_reduce.idx',
                    '_reduce.reduce_type',
                    '_reduce_contract.ref_contract_idx',
                    '_reduce_contract.duration',
                    '_reduce_contract.reduce_order_qty',
                ])
                ->leftJoin('_reduce_contract', '_reduce.idx', '_reduce_contract.ref_reduce_idx')
                ->whereBetween('_reduce.start_date', [$s_date, $e_date])
                ->whereIn('_reduce.reduce_type', [0, 2, 4])
                ->where('_reduce_contract.ref_contract_idx', $contract->idx)
                ->get();

            $reduce_details = [];
            foreach ($reduces as $reduce) {
                $wattage = $this->getReduceInfoFromWattageByContractIdx($reduce->idx, $contract->idx);
                $reduce->reduce_qty = (!is_null($wattage)) ? $wattage->reduce_qty : 0;
                $this->setReduceDrReportsIncludeData($report['include'], $reduce);
                $wattage->start_dateStr = Carbon::createFromTimestamp($wattage->start_date)->toDateString();
                $wattage->reduce_typeTitle = findConfigValueName($reduceTypes, $reduce->reduce_type);
                $wattage->s_time = Carbon::createFromTimestamp($wattage->start_date)->format('H:i');
                $wattage->e_time = Carbon::createFromTimestamp($wattage->start_date)->addMinute($wattage->duration)->format('H:i');
                $reduce_details[] = $wattage;

                $totals['include']['reduce_order_qty'] += $reduce->reduce_order_qty;
                $totals['include']['reduce_qty'] += $reduce->reduce_qty;
                if ($reduce->reduce_type == 2) continue;
                $this->setReduceDrReportsIncludeData($report['ninclude'], $reduce);
                $totals['ninclude']['reduce_order_qty'] += $reduce->reduce_order_qty;
                $totals['ninclude']['reduce_qty'] += $reduce->reduce_qty;
            }

            $selectRaw = "
                CASE WHEN 17_cbl is null THEN 0 ELSE round(sum(17_cbl) / count(*), 2) END as 17_cbl,
                CASE WHEN 18_cbl is null THEN 0 ELSE round(sum(18_cbl) / count(*), 2) END as 18_cbl,
                CASE WHEN 19_cbl is null THEN 0 ELSE round(sum(19_cbl) / count(*), 2) END as 19_cbl
            ";

            $report['reduce_details'] = $reduce_details;
            $totals['capacity'] += $report['capacity'];
            $report['cbl'] = WattageDay::selectRaw($selectRaw)
                ->where('kepco_code', $contract->kepco_code)
                ->where('ref_contract_idx', $contract->idx)
                ->whereBetween('ymd', [$after_30day, $yesterday])
                ->first()->toArray();

            foreach ($report['cbl'] as $key => $val) $totals['cbl'][$key] += $val;

            $this->setReduceDrReportsRateData($report['include']);
            $this->setReduceDrReportsRateData($report['ninclude']);
            /*if (! $today_have_event and $cacheIsNull) {
                $this->createCache($company_id, '참여고객전력수요 의무감축이력', $contract->idx, $report);
                $this->createCache($company_id, '참여고객전력수요 의무감축이력총계', $contract->idx, $totals);
            }*/
            $reports[] = $report;
        }
        $this->setReduceDrReportsRateData($totals['include']);
        $this->setReduceDrReportsRateData($totals['ninclude']);
        return [$totals, $reports];
    }

    /**
     * 참여고객 월별 정산 데이타 가져오기
     * Created by tk.kim 2018.04
     *
     * @param $global_contracts_idx
     * @param $ym
     * @return array
     */
    public function getContractAdjustsForMonthData($global_contracts_idx, $ym)
    {
        // $select = [
        //     'adjust_contract_details.*',
        //     '_reduce.idx as reduce_idx',
        //     '_reduce.reduce_type',
        //     '_dr.name as dr_name',
        //     '_reduce_contract_interval.duration',
        //     '_contract.name as contract_name',
        //     '_reduce_interval.start_h',
        // ];

        $details_total = [
            'default' => [
                'orc' => 0,
                'bp' => 0,
                'drbp' => 0,
                'pay_ddt' => 0,
                'pay_ddp' => 0
            ],
            'dr_profit' => [
                'orc' => 0,
                'rso' => 0,
                'xrsof' => 0,
                'dcbl' => 0,
                'me' => 0,
                'dr' => 0,
                'smp' => 0,
                'df' => 0,
                'drp' => 0,
                'pay_dpt' => 0,
                'pay_dpp' => 0,
            ],
            'sr_profit' => [
                'orc' => 0,
                'rso' => 0,
                'xrsof' => 0,
                'dcbl' => 0,
                'me' => 0,
                'dr' => 0,
                'smp' => 0,
                'df' => 0,
                'scbl' => 0,
                'sr' => 0,
                'pssr' => 0,
                'slrp' => 0,
                'drrp' => 0,
                'xdresmp' => 0,
                'pay_spt' => 0,
                'pay_spp' => 0,
            ],
            'dr_break' => [
                'orc' => 0,
                'bp' => 0,
                'drbp' => 0,
                'tdrbp' => 0,
                'mrt' => 0,
                'rso' => 0,
                'dr' => 0,
                'drd' => 0,
                'ibpc' => 0,
                'bpc' => 0,
                'pay_dbt' => 0,
                'pay_dbp' => 0,
            ],
            'sr_break' => [
                'orc' => 0,
                'rso' => 0,
                'dcbl' => 0,
                'me' => 0,
                'dr' => 0,
                'smp' => 0,
                'scbl' => 0,
                'sr' => 0,
                'pssr' => 0,
                'drrp' => 0,
                'ppc' => 0,
                'pay_sbp' => 0,
            ],
        ];

        $details = [
            'default' => [],
            'dr_profit' => [],
            'sr_profit' => [],
            'dr_break' => [],
            'sr_break' => [],
            'etc' => []
        ];

        $total = [
            'is_pay' => 'N',
            'total' => 0,
            'default' => 0,
            'profit' => 0,
            'break' => 0,
            'etc' => 0,
        ];

        $original_adjusts =
            AdjustContractDetail::select([
                'adjust_contract_details.*',
                '_reduce.idx as reduce_idx',
                '_reduce.reduce_type',
                '_dr.name as dr_name',
                '_reduce_contract_interval.duration',
                '_contract.name as contract_name',
                '_reduce_interval.start_h',
            ])->leftJoin('_reduce_contract_interval', function ($join) {
                $join->on('adjust_contract_details.ref_reduce_idx', '=', '_reduce_contract_interval.ref_reduce_idx')
                    ->on('adjust_contract_details.ref_contract_idx', '=', '_reduce_contract_interval.ref_contract_idx')
                    ->on('adjust_contract_details.ref_reduce_no', '=', '_reduce_contract_interval.no');
            })->leftJoin('_reduce_interval', function ($join) {
                $join->on('_reduce_interval.ref_reduce_idx', '=', 'adjust_contract_details.ref_reduce_idx')
                    ->on('_reduce_interval.no', '=', 'adjust_contract_details.ref_reduce_no');
            })->leftJoin('_reduce', '_reduce.idx', 'adjust_contract_details.ref_reduce_idx')
                ->leftJoin('_dr', '_dr.idx', 'adjust_contract_details.ref_dr_idx')
                ->leftJoin('_contract', '_contract.idx', 'adjust_contract_details.ref_contract_idx')
                ->whereIn('adjust_contract_details.ref_contract_idx', $global_contracts_idx)
                ->where('YM', $ym)
                ->orderBy('contract_name')
                ->get();

        $original_adjust = (object)['is_pay' => 'N'];
        foreach ($original_adjusts as $original_adjust) {
            if ($original_adjust->type == 'default') {
                $contract_default = $this->setContractAndUserDefault($original_adjust);
                $contract_default['pay_ddp'] = ($contract_default['pay_ddp'] != 0) ? round($contract_default['pay_ddp']) : 0;

                $details['default'][] = $contract_default;

                $details_total['default']['orc'] += $contract_default['orc'];
                $details_total['default']['bp'] += $contract_default['bp'];
                $details_total['default']['drbp'] += $contract_default['drbp'];
                $details_total['default']['pay_ddt'] += $contract_default['pay_ddt'];
                $details_total['default']['pay_ddp'] += $contract_default['pay_ddp'];

                $contract_dr_break = $this->setContractAndUserDrBreak($original_adjust);
                if ($original_adjust->DF == 1) {
                    $contract_dr_break['pay_dbp'] = ($contract_dr_break['pay_dbp'] != 0) ? round($contract_dr_break['pay_dbp']) : 0;
                    $contract_dr_break['ibpc'] = ($contract_dr_break['ibpc'] != 0) ? round($contract_dr_break['ibpc']) : 0;
                    $contract_dr_break['bpc'] = ($contract_dr_break['bpc'] != 0) ? round($contract_dr_break['bpc']) : 0;
                    $details['dr_break'][] = $contract_dr_break;
                    $details_total['dr_break']['orc'] += $contract_dr_break['orc'];
                    $details_total['dr_break']['bp'] += $contract_dr_break['bp'];
                    $details_total['dr_break']['drbp'] += $contract_dr_break['drbp'];
                    $details_total['dr_break']['tdrbp'] += $contract_dr_break['tdrbp'];
                    $details_total['dr_break']['mrt'] += $contract_dr_break['mrt'];
                    $details_total['dr_break']['rso'] += $contract_dr_break['rso'];
                    $details_total['dr_break']['dr'] += $contract_dr_break['dr'];
                    $details_total['dr_break']['drd'] += $contract_dr_break['drd'];
                    $details_total['dr_break']['ibpc'] += $contract_dr_break['ibpc'];
                    $details_total['dr_break']['bpc'] += $contract_dr_break['bpc'];
                    $details_total['dr_break']['pay_dbt'] += $contract_dr_break['pay_dbt'];
                    $details_total['dr_break']['pay_dbp'] += $contract_dr_break['pay_dbp'];
                }
            }

            if ($original_adjust->type == 'profit') {
                if ($original_adjust->reduce_type == 1) {
                    $contract_sr_profit = $this->setContractAndUserSrProfit($original_adjust);
                    $contract_sr_profit['pay_spp'] = ($contract_sr_profit['pay_spp'] != 0) ? round($contract_sr_profit['pay_spp']) : 0;
                    $details['sr_profit'][] = $contract_sr_profit;

                    $details_total['sr_profit']['orc'] += $contract_sr_profit['orc'];
                    $details_total['sr_profit']['rso'] += $contract_sr_profit['rso'];
                    $details_total['sr_profit']['xrsof'] += $contract_sr_profit['xrsof'];
                    $details_total['sr_profit']['dcbl'] += $contract_sr_profit['dcbl'];
                    $details_total['sr_profit']['me'] += $contract_sr_profit['me'];
                    $details_total['sr_profit']['dr'] += $contract_sr_profit['dr'];
                    $details_total['sr_profit']['smp'] += $contract_sr_profit['smp'];
                    $details_total['sr_profit']['df'] += $contract_sr_profit['df'];
                    $details_total['sr_profit']['scbl'] += $contract_sr_profit['scbl'];
                    $details_total['sr_profit']['sr'] += $contract_sr_profit['sr'];
                    $details_total['sr_profit']['pssr'] += $contract_sr_profit['pssr'];
                    $details_total['sr_profit']['slrp'] += $contract_sr_profit['slrp'];
                    $details_total['sr_profit']['drrp'] += $contract_sr_profit['drrp'];
                    $details_total['sr_profit']['xdresmp'] += $contract_sr_profit['xdresmp'];
                    $details_total['sr_profit']['pay_spt'] += $contract_sr_profit['pay_spt'];
                    $details_total['sr_profit']['pay_spp'] += $contract_sr_profit['pay_spp'];

                    $contract_sr_break = $this->setContractAndUserSrBreak($original_adjust);
                    $contract_sr_break['ppc'] = ($contract_sr_break['ppc'] != 0) ? round($contract_sr_break['ppc']) : 0;
                    $contract_sr_break['pay_sbp'] = ($contract_sr_break['pay_sbp'] != 0) ? round($contract_sr_break['pay_sbp']) : 0;
                    $details['sr_break'][] = $contract_sr_break;

                    $details_total['sr_break']['orc'] += $contract_sr_break['orc'];
                    $details_total['sr_break']['rso'] += $contract_sr_break['rso'];
                    $details_total['sr_break']['dcbl'] += $contract_sr_break['dcbl'];
                    $details_total['sr_break']['me'] += $contract_sr_break['me'];
                    $details_total['sr_break']['dr'] += $contract_sr_break['dr'];
                    $details_total['sr_break']['smp'] += $contract_sr_break['smp'];
                    $details_total['sr_break']['scbl'] += $contract_sr_break['scbl'];
                    $details_total['sr_break']['sr'] += $contract_sr_break['sr'];
                    $details_total['sr_break']['pssr'] += $contract_sr_break['pssr'];
                    $details_total['sr_break']['drrp'] += $contract_sr_break['drrp'];
                    $details_total['sr_break']['ppc'] += $contract_sr_break['ppc'];
                    $details_total['sr_break']['pay_sbp'] += $contract_sr_break['pay_sbp'];
                } else {
                    $contract_dr_profit = $this->setContractAndUserDrProfit($original_adjust);
                    $details['dr_profit'][] = $contract_dr_profit;

                    $details_total['dr_profit']['orc'] += $contract_dr_profit['orc'];
                    $details_total['dr_profit']['rso'] += $contract_dr_profit['rso'];
                    $details_total['dr_profit']['xrsof'] += $contract_dr_profit['xrsof'];
                    $details_total['dr_profit']['dcbl'] += $contract_dr_profit['dcbl'];
                    $details_total['dr_profit']['me'] += $contract_dr_profit['me'];
                    $details_total['dr_profit']['dr'] += $contract_dr_profit['dr'];
                    $details_total['dr_profit']['smp'] += $contract_dr_profit['smp'];
                    $details_total['dr_profit']['df'] += $contract_dr_profit['df'];
                    $details_total['dr_profit']['drp'] += $contract_dr_profit['drp'];
                    $details_total['dr_profit']['pay_dpt'] += $contract_dr_profit['pay_dpt'];
                    $details_total['dr_profit']['pay_dpp'] += $contract_dr_profit['pay_dpp'];
                }
            }

            unset($contract_default, $contract_dr_break, $contract_dr_profit, $contract_sr_break, $contract_sr_profit);
        }

        $adjustEtcDetails = AdjustEtcDetail::select([
            'adjust_etc_details.start_date',
            'adjust_etc_details.end_date',
            'adjust_etc_details.title',
            'adjust_etc_details.pay_method',
            'adjust_etc_details.pay_type',
            'adjust_etc_details.pay_value',
            '_dr.name as dr_name',
            '_contract.name as contract_name'
        ])->where([
            'type' => 'Contract',
            'YM' => $ym
        ])->whereIn('c_idx', $global_contracts_idx)
            ->leftJoin('_dr', '_dr.idx', 'adjust_etc_details.ref_dr_idx')
            ->leftJoin('_contract', '_contract.idx', 'adjust_etc_details.c_idx')
            ->orderBy('contract_name')
            ->get();

        $configs = [
            'AdjustsEtcRateType' => config('eims.AdjustsEtcRateType'),
            'AdjustsEtcRateMethod' => config('eims.AdjustsEtcRateMethod'),
        ];

        foreach ($adjustEtcDetails as $adjustEtcDetail) {
            $this->getAdjustEtcDetail($adjustEtcDetail, $configs, $total, $details);
        }

        $this->setAdjustContractTotalForMonth($original_adjust, $total, $details_total);

        return [
            'details' => $details,
            'details_total' => $details_total,
            'total' => $total,
        ];
    }

    /**
     * 참여고객 기간별 정산 데이타 가져오기
     * Created by tk.kim 2018.04
     *
     * @param $global_contracts_idx
     * @param $from_month
     * @param $to_month
     * @return array
     */
    public function getContractAdjustsForPeriodData($global_contracts_idx, $from_month, $to_month)
    {
        $select = "
            adjust_contract_details.*,
            round(adjust_contract_details.PAY_DDP) as PAY_DDP,
            _dr.name as dr_name,
            _contract.name as contract_name,
            _contract.idx as contract_idx,
            round(sum(sum_table.PAY_DPP))     AS sum_drp,
            round(sum(sum_table.PAY_SPP))     AS sum_slrp,
            round(sum(sum_table.PAY_SOPP))    AS sum_xdresmp,
            round(sum(sum_table.PAY_DBP))     AS sum_bpc,
            round(sum(sum_table.PAY_SBP))     AS sum_ppc,
            round(sum(sum_etc_table.pay_value))     AS sum_erp
        ";

        # DRBP 기본정산금 = PAY_DDP
        # drp 전력수요 의무감축 실적정산금 = PAY_DPP
        # SLRP 계획감축 실적정산금 = PAY_SPP
        # XDRESMP 계획감축 추가정산금 = PAY_SOPP
        # BPC 최종 전력수요 의무감축 위약금 = PAY_DBP
        # PPC 계획감축 위약금 = PAY_SBP

        $details_total = [
            'drbp' => 0,
            'drp' => 0,
            'slrp' => 0,
            'xdresmp' => 0,
            'bpc' => 0,
            'ppc' => 0,
            'etc' => 0
        ];

        $details = [];

        $total = [
            'total' => 0,
            'default' => 0,
            'profit' => 0,
            'break' => 0,
            'over_profit' => 0,
            'etc' => 0,
        ];

        $company_id = session('admin_login_info.company_id');
        $from_ym = Carbon::parse($from_month)->format('Ym');
        $to_ym = Carbon::parse($to_month)->format('Ym');
        $original_adjusts = AdjustContractDetail::selectRaw($select)
            ->leftJoin('adjust_contract_details as sum_table', function ($join) {
                $join->on('adjust_contract_details.YM', '=', 'sum_table.YM')
                    ->on('adjust_contract_details.ref_dr_idx', '=', 'sum_table.ref_dr_idx')
                    ->on('adjust_contract_details.ref_contract_idx', '=', 'sum_table.ref_contract_idx')
                    ->groupBy(['sum_table.ref_dr_idx', 'sum_table.ref_contract_idx', 'sum_table.YM']);
            })->leftJoin('adjust_etc_details as sum_etc_table', function ($join) {
                $join->on('sum_table.YM', '=', 'sum_etc_table.YM')
                    ->on('sum_table.ref_dr_idx', '=', 'sum_etc_table.ref_dr_idx')
                    ->on('sum_table.ref_contract_idx', '=', 'sum_etc_table.c_idx')
                    ->where('sum_table.type', 'default');
            })
            ->leftJoin('_contract', 'adjust_contract_details.ref_contract_idx', '_contract.idx')
            ->leftJoin('_dr', 'adjust_contract_details.ref_dr_idx', '_dr.idx')
            ->where(['adjust_contract_details.type' => 'default', '_dr.company_id' => $company_id])
            ->whereBetween('adjust_contract_details.YM', [$from_ym, $to_ym])
            ->whereIn('adjust_contract_details.ref_contract_idx', $global_contracts_idx)
            ->groupBy(['adjust_contract_details.ref_dr_idx', 'adjust_contract_details.ref_contract_idx', 'adjust_contract_details.YM'])
            ->orderBy('contract_name')
            ->get();

        $months = $this->getMonths($from_month, $to_month);
        foreach ($original_adjusts as $original_adjust) {
            $dr_period = $this->setContractAdjustForPeriod($original_adjust);
            $details[] = $dr_period;

            $details_total['drp'] += $dr_period['drp'];
            $details_total['drbp'] += $dr_period['drbp'];
            $details_total['slrp'] += $dr_period['slrp'];
            $details_total['xdresmp'] += $dr_period['xdresmp'];
            $details_total['bpc'] += $dr_period['bpc'];
            $details_total['ppc'] += $dr_period['ppc'];
            $details_total['etc'] += $dr_period['etc'];

            unset($months[$original_adjust['YM']]);
        }

        $this->getAdjustTotalForPeriod($details_total, $total);

        return [
            'details' => $details,
            'details_total' => $details_total,
            'total' => $total,
            'month' => $months
        ];
    }

    /**
     * 수요자원 월별 정산 데이타 가져오기
     * Created by tk.kim 2018.04
     *
     * @param $month
     * @return array
     */
    public function getDrAdjustsForMonthData($month)
    {
        $company_id = session('admin_login_info.company_id');

        $reduce_intervals = ReduceInterval::select('_reduce_interval.idx')
            ->leftJoin('_reduce', '_reduce_interval.ref_reduce_idx', '_reduce.idx', '_reduce.start_date')
            ->leftJoin('_dr', '_reduce.ref_dr_id', '_dr.id')
            ->where('_dr.company_id', $company_id)
            ->where('_reduce_interval.adjust_price', 0)
            ->whereBetween('_reduce.start_date', [
                Carbon::parse($month)->firstOfMonth()->timestamp,
                Carbon::parse($month)->lastOfMonth()->timestamp
            ])
            ->whereIn('_reduce.reduce_type', [0, 1, 4])
            ->first();

        $select = [
            'adjust_dr_details.*',
            '_dr.name as dr_name',
            '_reduce.idx as reduce_idx',
            '_reduce.reduce_type',
            '_reduce_interval.duration',
            '_reduce_interval.start_h',
        ];

        $details_total = [
            'default' => [
                'orc' => 0, 'bp' => 0, 'drbp' => 0,
            ],
            'dr_profit' => [
                'orc' => 0, 'rso' => 0, 'xrsof' => 0, 'dcbl' => 0,
                'me' => 0, 'dr' => 0, 'smp' => 0, 'df' => 0, 'drp' => 0,
            ],
            'sr_profit' => [
                'orc' => 0, 'rso' => 0, 'xrsof' => 0, 'dcbl' => 0,
                'me' => 0, 'dr' => 0, 'smp' => 0, 'df' => 0,
                'scbl' => 0, 'sr' => 0, 'pssr' => 0, 'slrp' => 0,
                'drrp' => 0, 'xdresmp' => 0,
            ],
            'dr_break' => [
                'orc' => 0, 'bp' => 0, 'drbp' => 0, 'tdrbp' => 0,
                'mrt' => 0, 'rso' => 0, 'dr' => 0, 'drd' => 0,
                'ibpc' => 0, 'bpc' => 0, 'tdrbp_value' => 0,
            ],
            'sr_break' => [
                'orc' => 0, 'rso' => 0, 'dcbl' => 0, 'me' => 0,
                'dr' => 0, 'smp' => 0, 'scbl' => 0, 'sr' => 0,
                'pssr' => 0, 'drrp' => 0, 'ppc' => 0,
            ],
        ];

        $details = [
            'default' => [],
            'dr_profit' => [],
            'sr_profit' => [],
            'dr_break' => [],
            'sr_break' => [],
        ];

        $total = [
            'is_pay' => 'N',
            'total' => 0,
            'default' => 0,
            'profit' => 0,
            'break' => 0,
        ];

        $ym = Carbon::parse($month)->format('Ym');

        $original_adjusts = AdjustDrDetail::select($select)
            ->leftJoin('_reduce', 'adjust_dr_details.ref_reduce_idx', '_reduce.idx')
            ->leftJoin('_reduce_interval', function ($join) {
                $join->on('_reduce_interval.ref_reduce_idx', '=', 'adjust_dr_details.ref_reduce_idx')
                    ->on('_reduce_interval.no', '=', 'adjust_dr_details.ref_reduce_no');
            })
            ->leftJoin('_dr', 'adjust_dr_details.ref_dr_idx', '_dr.idx')
            ->where(['YM' => $ym, '_dr.company_id' => $company_id])
            ->get();

        $original_adjust = (object)['is_pay' => 'N'];
        foreach ($original_adjusts as $original_adjust) {

            if ($original_adjust->type == 'default') {
                $dr_default = $this->setDrDefault($original_adjust);
                $details['default'][] = $dr_default;

                $details_total['default']['orc'] += $dr_default['orc'];
                $details_total['default']['bp'] += $dr_default['bp'];
                $details_total['default']['drbp'] += $dr_default['drbp'];

                if ($original_adjust->DF == 1) {
                    $dr_break = $this->setDrBreak($original_adjust);
                    $details['dr_break'][] = $dr_break;

                    $details_total['dr_break']['orc'] += $dr_break['orc'];
                    $details_total['dr_break']['bp'] += $dr_break['bp'];
                    $details_total['dr_break']['drbp'] += $dr_break['drbp'];
                    $details_total['dr_break']['tdrbp'] += $dr_break['tdrbp'];
                    $details_total['dr_break']['mrt'] += $dr_break['mrt'];
                    $details_total['dr_break']['rso'] += $dr_break['rso'];
                    $details_total['dr_break']['dr'] += $dr_break['dr'];
                    $details_total['dr_break']['drd'] += $dr_break['drd'];
                    $details_total['dr_break']['ibpc'] += $dr_break['ibpc'];
                    $details_total['dr_break']['bpc'] += $dr_break['bpc'];
                }
            }

            if ($original_adjust->type == 'profit') {
//                if ($original_adjust->reduce_type == 1) {
                if (in_array($original_adjust->reduce_type, array(1, 7, 8))) {
                    $sr_profit = $this->setSrProfit($original_adjust);
                    $details['sr_profit'][] = $sr_profit;

                    $details_total['sr_profit']['orc'] += $sr_profit['orc'];
                    $details_total['sr_profit']['rso'] += $sr_profit['rso'];
                    $details_total['sr_profit']['xrsof'] += $sr_profit['xrsof'];
                    $details_total['sr_profit']['dcbl'] += $sr_profit['dcbl'];
                    $details_total['sr_profit']['me'] += $sr_profit['me'];
                    $details_total['sr_profit']['dr'] += $sr_profit['dr'];
                    $details_total['sr_profit']['smp'] += $sr_profit['smp'];
                    $details_total['sr_profit']['df'] += $sr_profit['df'];
                    $details_total['sr_profit']['scbl'] += $sr_profit['scbl'];
                    $details_total['sr_profit']['sr'] += $sr_profit['sr'];
                    $details_total['sr_profit']['pssr'] += $sr_profit['pssr'];
                    $details_total['sr_profit']['slrp'] += $sr_profit['slrp'];
                    $details_total['sr_profit']['drrp'] += $sr_profit['drrp'];
                    $details_total['sr_profit']['xdresmp'] += $sr_profit['xdresmp'];

                    $sr_break = $this->setSrBreak($original_adjust);
                    $details['sr_break'][] = $sr_break;

                    $details_total['sr_break']['orc'] += $sr_break['orc'];
                    $details_total['sr_break']['rso'] += $sr_break['rso'];
                    $details_total['sr_break']['dcbl'] += $sr_break['dcbl'];
                    $details_total['sr_break']['me'] += $sr_break['me'];
                    $details_total['sr_break']['dr'] += $sr_break['dr'];
                    $details_total['sr_break']['smp'] += $sr_break['smp'];
                    $details_total['sr_break']['scbl'] += $sr_break['scbl'];
                    $details_total['sr_break']['sr'] += $sr_break['sr'];
                    $details_total['sr_break']['pssr'] += $sr_break['pssr'];
                    $details_total['sr_break']['drrp'] += $sr_break['drrp'];
                    $details_total['sr_break']['ppc'] += $sr_break['ppc'];
                } else {
                    $dr_profit = $this->setDrProfit($original_adjust);
                    $details['dr_profit'][] = $dr_profit;

                    $details_total['dr_profit']['orc'] += $dr_profit['orc'];
                    $details_total['dr_profit']['rso'] += $dr_profit['rso'];
                    $details_total['dr_profit']['xrsof'] += $dr_profit['xrsof'];
                    $details_total['dr_profit']['dcbl'] += $dr_profit['dcbl'];
                    $details_total['dr_profit']['me'] += $dr_profit['me'];
                    $details_total['dr_profit']['dr'] += $dr_profit['dr'];
                    $details_total['dr_profit']['smp'] += $dr_profit['smp'];
                    $details_total['dr_profit']['df'] += $dr_profit['df'];
                    $details_total['dr_profit']['drp'] += $dr_profit['drp'];
                }
            }

            unset($dr_default, $dr_break, $dr_profit, $sr_break, $sr_profit);
        }

        $this->setAdjustTotalForMonth($original_adjust, $total, $details_total);

        return [
            'details' => $details,
            'details_total' => $details_total,
            'total' => $total,
            'is_smp_show' => !is_null($reduce_intervals)
        ];
    }

    /**
     * 수요자원 월별 정산 데이타 가져오기
     * Created by tk.kim 2018.04
     * @param $from_month
     * @param $to_month
     * @return array
     */
    public function getDrAdjustsForPeriodData($from_month, $to_month)
    {
        $select = "
            adjust_dr_details.*,
            _dr.name as dr_name,
            round(sum(sum_table.drp), 2)     AS sum_drp,
            round(sum(sum_table.slrp), 2)    AS sum_slrp,
            round(sum(sum_table.xdresmp), 2) AS sum_xdresmp,
            round(sum(sum_table.bpc), 2)     AS sum_bpc,
            round(sum(sum_table.ppc), 2)     AS sum_ppc
        ";

        $details_total = [
            'drbp' => 0,
            'drp' => 0,
            'slrp' => 0,
            'xdresmp' => 0,
            'bpc' => 0,
            'ppc' => 0,
        ];

        $details = [];

        $total = [
            'total' => 0,
            'default' => 0,
            'profit' => 0,
            'break' => 0,
            'over_profit' => 0,
        ];

        $company_id = session('admin_login_info.company_id');
        $from_ym = Carbon::parse($from_month)->format('Ym');
        $to_ym = Carbon::parse($to_month)->format('Ym');

        $original_adjusts = AdjustDrDetail::selectRaw($select)
            ->leftJoin('adjust_dr_details as sum_table', function ($join) {
                $join->on('adjust_dr_details.YM', '=', 'sum_table.YM')
                    ->on('adjust_dr_details.ref_dr_idx', '=', 'sum_table.ref_dr_idx')
                    ->selectRaw("sum_table.YM, sum(sum_table.drp) ,sum(sum_table.slrp) ,sum(sum_table.xdresmp) ,sum(sum_table.bpc) ,sum(sum_table.ppc) as ppc")
                    ->groupBy(['sum_table.ref_dr_idx', 'sum_table.YM']);
            })
            ->leftJoin('_dr', 'adjust_dr_details.ref_dr_idx', '_dr.idx')
            ->where(['adjust_dr_details.type' => 'default', '_dr.company_id' => $company_id])
            ->whereBetween('adjust_dr_details.YM', [$from_ym, $to_ym])
            ->groupBy(['adjust_dr_details.ref_dr_idx', 'adjust_dr_details.YM'])
            ->get();

        $months = $this->getMonths($from_month, $to_month);
        foreach ($original_adjusts as $original_adjust) {
            $dr_period = $this->setDrAdjustForPeriod($original_adjust);
            $details[] = $dr_period;

            $details_total['drp'] += $dr_period['drp'];
            $details_total['drbp'] += $dr_period['drbp'];
            $details_total['slrp'] += $dr_period['slrp'];
            $details_total['xdresmp'] += $dr_period['xdresmp'];
            $details_total['bpc'] += $dr_period['bpc'];
            $details_total['ppc'] += $dr_period['ppc'];

            unset($months[$original_adjust['YM']]);
        }

        $this->getAdjustTotalForPeriod($details_total, $total);

        $message = (empty($months)) ? '' : ' ※ 거래월 ' . implode(', ', array_keys($months)) . '은 계산이력이 없습니다. ';
        return [
            'details' => $details,
            'details_total' => $details_total,
            'total' => $total,
            'warning_message' => $message
        ];
    }

    /**
     * 월별 정산현황(대체천연가스) 월별 정산 데이타 가져오기
     * Created by tk.kim 2018.06
     *
     * @param $month
     * @return array
     */
    public function getCnSystemGasAdjustsForMonthData($month)
    {
        $ym = Carbon::parse($month)->format('Ym');

        // 저장된 정산 내용 가져오기
        $original_data = CnSystemGasAdjust::where('YM', $ym)->get();

        $s_date = Carbon::parse($month)->format('Ym01');
        $e_date = Carbon::parse($month)->lastOfMonth()->format('Ymd');

        $resources = [];
        foreach ($original_data as $data) {
            $resources[$data->kepco_code][$data->date] = $data;
        }

        $totals = [];
        $original_data = CnSystemGasAdjust::selectRaw('kepco_code, round(sum(oil)) as oil, round(sum(sale_price)) as sale_price')->where('YM', $ym)->groupBy(['kepco_code'])->get();
        foreach ($original_data as $data) {
            $totals[$data->kepco_code] = $data;
        }

        // 해당월 일자 가져오기
        $dates = $this->getDates($s_date, $e_date, 'Y-m-d');

        // 판매단가 산정방법 내용 가져오기
        $guide = CnSystemGasAdjustResource::selectRaw("
            sum(gas_price) / count(gas_price) as gas_price,
            sum(month_calories_avg) / count(month_calories_avg) as month_calories_avg,
            sum(secure_price) / count(secure_price) as secure_price,
            sum(addons_price) / count(addons_price) as addons_price,
            sum(material_percent) / count(material_percent) as material_percent
        ")->whereBetween('ymd', [$s_date, $e_date])->first();

        $return = [
            'dates' => $dates,
            'guide' => $guide,
            'resources' => $resources,
            'totals' => $totals
        ];

        return $return;
    }

    /**
     * 월별 정산현황(대체천연가스) 월별 정산 데이타 재계산
     * Created by tk.kim 2018.06
     */
    public function putCnSystemGasAdjustsForMonth()
    {
        $this->validate($this->request, [
            'month' => 'required|date'
        ]);

        $month = $this->request->get('month');
        $ym = Carbon::parse($month)->format('Ym');

        // 기존 생성된 내용 삭제
        CnSystemGasAdjust::where('YM', $ym)->delete();

        $s_date = Carbon::parse($month)->format('Ym01');
        $e_date = Carbon::parse($month)->lastOfMonth()->format('Ymd');

        // 정산 필요한 내용 가져오기
        $contracts = Contract::select(['_contract.idx', '_contract.kepco_code', '_contract.name', 'contract_addons.ref_contract_idx'])
            ->leftJoin('contract_addons', '_contract.idx', 'contract_addons.ref_contract_idx')
            ->with(['CnSystemGasContractResource' => function ($sql) use ($s_date, $e_date) {
                $sql->select([
                    'cn_system_gas_contract_resources.kepco_code',
                    'cn_system_gas_contract_resources.ymd',
                    'cn_system_gas_adjust_resources.lfg_unit_price',
                    'cn_system_gas_adjust_resources.gas_price',
                    'cn_system_gas_adjust_resources.sale_price'
                ])
                    ->selectRaw("sum(cn_system_gas_contract_resources.oil) as oil, round(avg(cn_system_gas_contract_resources.metan), 2) as metan")
                    ->leftJoin('cn_system_gas_adjust_resources', 'cn_system_gas_adjust_resources.ymd', 'cn_system_gas_contract_resources.ymd')
                    ->whereBetween('cn_system_gas_contract_resources.ymd', [$s_date, $e_date])
                    ->groupBy('cn_system_gas_contract_resources.kepco_code', 'cn_system_gas_contract_resources.ymd');
            }])
            ->whereIn('_contract.idx', $this->getPermissionContractsIdx())
            ->where('contract_addons.cn_system_gas_adjust', 'Y')
            ->orderBy('regdate')
            ->get();

        $resources = [];
        $insert = [];
        foreach ($contracts as $contract) {
            foreach ($contract['CnSystemGasContractResource']->toArray() as $item) {
                // 계산 수식 적용
//                 code: '0000000007',
//                 title: '에코바이오',
//                 kcal: '③ = ① × ② × 9,520',
//                 apply_price: '⑤ = (② ÷ 50％) × 단가',
//                 sale_price: '⑥ = ① × ⑤',
//               },
//               {
//                 code: '0000000009',
//                 title: '대전바이오',
//                 kcal: '③ = ① × ② × 9,500',
//                 apply_price: '⑤ = 열2요금 × 0.5237',
//                 sale_price: '⑥ = ④ × ⑤',
//               },
//               {
//                 code: '0000000008',
//                 title: '대전열병합',
//                 kcal: '③ = ① × ② × 9,520',
//                 apply_price: '⑤',
//                 sale_price: '⑥ = ④ × ⑤',
                $item['YM'] = $ym;
                $item['date'] = Carbon::parse($item['ymd'])->toDateString();
                if ($item['kepco_code'] === '0000000007') { // 에코바이오
                    $item['kcal'] = round($item['oil'] * ($item['metan'] / 100) * 9520);
                    $item['kcal_mj'] = round($item['kcal'] * 0.0041868);
                    $item['apply_price'] = round($item['metan'] / 100 / 0.5 * (is_null($item['lfg_unit_price']) ? 0 : $item['lfg_unit_price']), 4);
                    $item['sale_price'] = round($item['oil'] * $item['apply_price']);
                } else if ($item['kepco_code'] === '0000000008') { // 대전열병합
                    $item['kcal'] = round($item['oil'] * ($item['metan'] / 100) * 9520);
                    $item['kcal_mj'] = round($item['kcal'] * 0.0041868);
                    # 판매단가
                    $item['apply_price'] = $item['sale_price'];
                    $item['sale_price'] = round($item['kcal_mj'] * $item['apply_price']);
                } else if ($item['kepco_code'] === '0000000009') { // 대전바이오
                    $item['kcal'] = round($item['oil'] * ($item['metan'] / 100) * 9500);
                    $item['kcal_mj'] = round($item['kcal'] * 0.0041868);
                    $item['apply_price'] = round($item['gas_price'] * 0.5237, 5);
                    $item['sale_price'] = round($item['kcal_mj'] * $item['apply_price']);
                }
                unset($item['lfg_unit_price'], $item['ymd'], $item['gas_price']);
                $resources[$contract->kepco_code][$item['date']] = $item;
                $insert[] = $item;
            }
            unset($contract['CnSystemGasContractResource']);
        }

        // 일괄 저장
        CnSystemGasAdjust::insert($insert);

        // 해당월 일자 가져오기
//                $dates = $this->getDates($s_date, $e_date, 'Y-m-d');
        // 판매단가 산정방법 내용 가져오기
//        $guide = CnSystemGasAdjustResource::selectRaw("
//            sum(gas_price) / count(gas_price) as gas_price,
//            sum(month_calories_avg) / count(month_calories_avg) as month_calories_avg,
//            sum(secure_price) / count(secure_price) as secure_price,
//            sum(addons_price) / count(addons_price) as addons_price,
//            sum(material_percent) / count(material_percent) as material_percent
//        ")->whereBetween('ymd', [$s_date, $e_date])->first();
//
//        return [
//            'date' => $dates,
//            'resource' => $resources,
//            'guide' => $guide
//        ];
    }
}
