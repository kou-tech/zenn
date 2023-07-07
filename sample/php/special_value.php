<?php
// NAN
$nan = acos(2); // acos(2)は未定義なのでNaNになる
var_dump($nan); // NAN
var_dump(is_nan($nan)); // true

// 無限大
$inf = log(0); // log(0)は無限大になる
var_dump($inf); // -INF
var_dump(is_infinite($inf)); // true

// ゼロ除算
$zero_positive = 1.0;
$zero_negative = -1.0;
var_dump(is_finite($zero_positive)); // true
var_dump(is_finite($zero_negative)); // true
var_dump(1/$zero_positive == 1/$zero_negative); // false