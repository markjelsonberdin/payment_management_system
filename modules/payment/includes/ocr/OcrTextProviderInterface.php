<?php
declare(strict_types=1);

interface OcrTextProviderInterface
{
    /** @return array{raw_text:?string,provider:string,feature:string} */
    public function extractDocumentText(string $imageBytes): array;
}
