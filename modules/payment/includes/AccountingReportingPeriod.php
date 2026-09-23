<?php
declare(strict_types=1);
final class AccountingReportingPeriod {
 public const TIMEZONE='Asia/Manila';
 public static function fromRequest(array $input,?DateTimeImmutable $now=null):array{
  $tz=new DateTimeZone(self::TIMEZONE);$now=($now??new DateTimeImmutable('now',$tz))->setTimezone($tz);$period=(string)($input['period']??'today');
  if($period==='custom'){$startText=trim((string)($input['start_date']??''));$endText=trim((string)($input['end_date']??''));$start=DateTimeImmutable::createFromFormat('!Y-m-d',$startText,$tz);$end=DateTimeImmutable::createFromFormat('!Y-m-d',$endText,$tz);if(!$start||!$end||$startText!==$start->format('Y-m-d')||$endText!==$end->format('Y-m-d')||$start>$end)throw new InvalidArgumentException('Custom report dates are invalid.');return['period'=>'custom','timezone'=>self::TIMEZONE,'start_at'=>$start,'end_exclusive'=>$end->modify('+1 day'),'prior_start'=>null,'prior_end_exclusive'=>null,'comparison_label'=>null];}
  $period=in_array($period,['today','week','month','year'],true)?$period:'today';
  if($period==='today'){$start=$now->setTime(0,0);$priorStart=$start->modify('-1 day');$priorEnd=$priorStart->add($start->diff($now));}
  elseif($period==='week'){$start=$now->modify('monday this week')->setTime(0,0);$priorStart=$start->modify('-7 days');$priorEnd=$priorStart->add($start->diff($now));}
  elseif($period==='month'){$start=$now->modify('first day of this month')->setTime(0,0);$priorStart=$start->modify('first day of previous month')->setTime(0,0);$day=min((int)$now->format('j'),(int)$priorStart->format('t'));$priorEnd=$priorStart->setDate((int)$priorStart->format('Y'),(int)$priorStart->format('n'),$day)->setTime((int)$now->format('H'),(int)$now->format('i'),(int)$now->format('s'));}
  else{$start=$now->setDate((int)$now->format('Y'),1,1)->setTime(0,0);$priorStart=$start->modify('-1 year');$year=(int)$priorStart->format('Y');$month=(int)$now->format('n');$day=min((int)$now->format('j'),cal_days_in_month(CAL_GREGORIAN,$month,$year));$priorEnd=$priorStart->setDate($year,$month,$day)->setTime((int)$now->format('H'),(int)$now->format('i'),(int)$now->format('s'));}
  return['period'=>$period,'timezone'=>self::TIMEZONE,'start_at'=>$start,'end_exclusive'=>$now,'prior_start'=>$priorStart,'prior_end_exclusive'=>$priorEnd,'comparison_label'=>'vs same elapsed period '.($period==='today'?'yesterday':'last '.$period)];
 }
 public static function sql(DateTimeInterface $date):string{return $date->format('Y-m-d H:i:s.u');}
}
