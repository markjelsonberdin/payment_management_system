<?php
declare(strict_types=1);

final class ReceiptParserService
{
    public const VERSION = 'receipt_parser_v1';

    /** @return array<string,mixed> */
    public function parse(?string $rawText): array
    {
        $text = trim(preg_replace('/[\t ]+/', ' ', str_replace(["\r\n", "\r"], "\n", (string) $rawText)) ?? '');
        if ($text === '') return $this->result($text, null, null, null, null, null, 0.0, ['NO_TEXT','MANUAL_REVIEW_REQUIRED']);

        $reference = $this->first($text, [
            '/(?:reference|ref(?:erence)?\s*(?:no|number)?|transaction\s*(?:id|no))\s*[:#-]?\s*([A-Z0-9-]{6,40})/i',
        ]);
        $amountRaw = $this->first($text, [
            '/(?:amount|total|paid)\s*[:\-]?\s*(?:PHP|P|₱)?\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.\d{2})|[0-9]+(?:\.\d{2}))/i',
            '/(?:PHP|₱)\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.\d{2})|[0-9]+(?:\.\d{2}))/i',
        ]);
        $amount = $amountRaw !== null ? (float) str_replace(',', '', $amountRaw) : null;
        $dateRaw = $this->first($text, [
            '/\b(20\d{2}[-\/]\d{1,2}[-\/]\d{1,2})\b/',
            '/\b(\d{1,2}[-\/]\d{1,2}[-\/]20\d{2})\b/',
        ]);
        $date = $this->date($dateRaw);
        $time = $this->time($this->first($text, ['/\b((?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?(?:\s*[AP]M)?)\b/i']));
        $channel = $this->channel($text);
        $missing = [];
        foreach (['REFERENCE'=>$reference,'AMOUNT'=>$amount,'DATE'=>$date] as $key=>$value) if ($value === null) $missing[]=$key.'_NOT_DETECTED';
        if($channel===null)$missing[]='UNKNOWN_RECEIPT_FORMAT';
        $confidence = max(0.0, 100.0 - count($missing) * 20.0);
        if($confidence<60.0)$missing[]='LOW_OCR_QUALITY';
        if($missing!==[])$missing[]='MANUAL_REVIEW_REQUIRED';
        return $this->result($text, $amount, $channel, $reference, $date, $time, $confidence, $missing);
    }

    /** @return array<string,mixed> */
    private function result(string $text, ?float $amount, ?string $channel, ?string $reference, ?string $date, ?string $time, float $confidence, array $quality): array
    {
        return ['normalized_text'=>$text,'amount'=>$amount,'bank_name'=>$channel,'reference_number'=>$reference,'transaction_date'=>$date,'transaction_time'=>$time,'confidence_score'=>$confidence,'quality'=>['parser_version'=>self::VERSION,'indicators'=>$quality]];
    }
    private function first(string $text,array $patterns):?string{foreach($patterns as $pattern)if(preg_match($pattern,$text,$m))return strtoupper(trim($m[1]));return null;}
    private function channel(string $text):?string{foreach(['GCASH'=>'GCash','PAYMAYA'=>'Maya','MAYA'=>'Maya','PAYMONGO'=>'PayMongo','AUB'=>'AUB','ASIA UNITED BANK'=>'AUB','BDO'=>'BDO','BPI'=>'BPI','UNIONBANK'=>'UnionBank','LANDBANK'=>'LandBank','METROBANK'=>'Metrobank'] as $needle=>$label)if(stripos($text,$needle)!==false)return $label;return null;}
    private function date(?string $value):?string{if($value===null)return null;$value=str_replace('/','-',$value);$formats=str_starts_with($value,'20')?['!Y-n-j']:['!n-j-Y','!j-n-Y'];foreach($formats as $format){$date=DateTimeImmutable::createFromFormat($format,$value);$errors=DateTimeImmutable::getLastErrors();if($date!==false&&($errors===false||($errors['warning_count']===0&&$errors['error_count']===0)))return $date->format('Y-m-d');}return null;}
    private function time(?string $value):?string{if($value===null)return null;foreach(['!H:i:s','!H:i','!g:i A'] as $format){$time=DateTimeImmutable::createFromFormat($format,$value);$errors=DateTimeImmutable::getLastErrors();if($time!==false&&($errors===false||($errors['warning_count']===0&&$errors['error_count']===0)))return $time->format('H:i:s');}return null;}
}
