<?php

namespace App\Services\CommerceSafety;

final readonly class CartValidation
{
    /**
     * @param  list<string>  $errors
     * @param  list<array{product_id:int,sku:string,name:string,method:string,qty:int,price_minor:int,line_total_minor:int}>  $lines
     */
    public function __construct(
        public bool $valid,
        public array $errors,
        public array $lines,
        public int $totalMinor,
        public bool $vip,
        public string $fingerprint,
        public bool $requiresManualHandling = false,
    ) {}
}
