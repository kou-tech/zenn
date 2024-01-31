---
title: "PHPUnitでブラックボックステスト技法を学ぶ"
emoji: "🔖"
type: "tech" # tech: 技術記事 / idea: アイデア
topics: ['php','phpunit','テスト']
published: true
---
## 導入
ブラックボックステスト技法は、テスト対象の内部構造ではなく、入出力（外から見た振る舞い）に着目するテスト技法です。これをPHPUnitで実際にコードを書きながら解説していきます。

今回は注文商品を例に取り上げます。はじめに、サンプルとして参照する注文商品クラスのコードを示します。

```php
<?php
declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use Exception;

class Order
{
    public function __construct(
        private int $id,
        private string $name,
        private string $description,
        private float $price,
        private int $quantity,
        private string $status,
        private DateTimeImmutable $orderDate,
    ) {}

    /**
     * 注文日から3日以内であればキャンセルが可能
     *
     * @param DateTimeImmutable $now
     * @return bool
     */
    public function isCancelable(
        DateTimeImmutable $now
    ): bool
    {
        // 注文日より前の日付はキャンセル不可
        if ($now < $this->orderDate) {
            return false;
        }

        $interval = $now->diff($this->orderDate);
        return $interval->days <= 3;
    }

    /**
     * 注文料金を計算する
     *
     * @param array<string, DateTimeImmutable> $campaignPeriod
     */
    public function calculatePrice(
        array $campaignPeriod
    ): int
    {
        $price = $this->price * $this->quantity;

        // キャンペーン期間中は10%割引
        if ($campaignPeriod['start'] <= $this->orderDate && $this->orderDate <= $campaignPeriod['end']) {
            $price = $price * 0.9;
        }

        // 限定商品は20%割引
        if ($this->isLimited()) {
            $price = $price * 0.8;
        }

        // 注文数が10以上なら5%割引
        if ($this->quantity >= 10) {
            $price = $price * 0.95;
        }

        // 端数は切り捨て
        return (int)floor($price);
    }

    /**
     * 限定商品かどうかを判定する
     */
    private function isLimited(): bool
    {
        // 限定商品の判定ロジック
        if ($this->name === '限定商品') {
            return true;
        }

        return true;
    }

    /**
     * キャンセル処理
     */
    public function cancel(): Order
    {
        // 発送準備中以外はキャンセル不可
        if ($this->status !== 'shipping_ready') {
            throw new Exception('キャンセル不可');
        }

        // キャンセル処理
        return new Order(
            $this->id,
            $this->name,
            $this->description,
            $this->price,
            $this->quantity,
            'canceled',
            $this->orderDate,
        );
    }

    /**
     * 発送処理
     */
    public function ship(): Order
    {
        // 発送準備中以外は発送不可
        if ($this->status !== 'shipping_ready') {
            throw new Exception('発送不可');
        }

        // 発送処理
        return new Order(
            $this->id,
            $this->name,
            $this->description,
            $this->price,
            $this->quantity,
            'shipped',
            $this->orderDate,
        );
    }

    /**
     * キャンセル済みかどうかを判定する
     */
    public function isCanceled(): bool
    {
        return $this->status === 'canceled';
    }

    /**
     * 発送済みかどうかを判定する
     */
    public function isShipped(): bool
    {
        return $this->status === 'shipped';
    }
}

```

## 同値分割法(Equivalence Partitioning)
同値分割法は、テストデータを同等に扱われるパーティションに分け、各パーティションから任意の一つの値を選んでテストを行う技法です。

注文キャンセルが可能かを判断する関数は次のように定義されています。

```php
    /**
     * 注文日から3日以内であればキャンセルが可能
     *
     * @param DateTimeImmutable $now
     * @return bool
     */
    public function isCancelable(
        DateTimeImmutable $now
    ): bool
    {
        // 注文日より前の日付はキャンセル不可
        if ($now < $this->orderDate) {
            return false;
        }

        $interval = $now->diff($this->orderDate);
        return $interval->days <= 3;
    }
```

この場合、入力値を次の3つのグループに分けてテストします：

1. 注文日より前（キャンセル不可）
2. 注文日から3日以内（キャンセル可能）
3. 注文日から4日以降（キャンセル不可）

:::message
グループ分けの判断や代表値の選び方は、テスト分析やテスト設計の段階で決定されます。
:::

```php

    /**
     * 3日以内の注文はキャンセル可能
     */
    public function testIsCancelableWithin3Days()
    {
        $order = new Order(
            1,
            'PHP Book',
            'PHP Book',
            1000,
            1,
            'shipping_ready',
            new DateTimeImmutable('2024-01-01')
        );

        $this->assertTrue($order->isCancelable(new DateTimeImmutable('2024-01-02')));
    }

    /**
     * 4日以降の注文はキャンセル不可
     */
    public function testIsCancelableAfter3Days()
    {
        $order = new Order(
            1,
            'PHP Book',
            'PHP Book',
            1000,
            1,
            'shipping_ready',
            new DateTimeImmutable('2024-01-01')
        );

        $this->assertFalse($order->isCancelable(new DateTimeImmutable('2024-02-01')));
    }

    /**
     * 注文日より前の日付はキャンセル不可
     */
    public function testIsCancelableBeforeOrderDate()
    {
        $order = new Order(
            1,
            'PHP Book',
            'PHP Book',
            1000,
            1,
            'shipping_ready',
            new DateTimeImmutable('2024-01-01')
        );

        $this->assertFalse($order->isCancelable(new DateTimeImmutable('2023-12-31')));
    }

```

それぞれのパーティションから任意の値を一つ選びテストすることで、他のパーティションに属する値も同様に処理されると仮定します。有効な値と無効な値を含むパーティションに区別することが重要です。
この方法により、すべての値をテストすることなく、効率的にテストを行うことができます。
同値分割法は、入力値の組み合わせが少なく、パーティション分けが可能な場合に特に有効です。

## 境界値分析(Boundary Value Analysis)
境界値分析は、入力値の範囲の境界付近に注目したテスト技法です。
注文キャンセルの判断を例に、注目すべき境界は「注文日」と「注文日から3日目」です。
以下の3つの観点からテストを行います
- その直前
- 境界値
- 直後

このアプローチにより、関数の境界値周辺での挙動を正確に評価できます。以下にテストコードを示します。

```php

    /**
     * 注文日の前日はキャンセル不可
     */
    public function testIsCancelableBeforeOrderDate()
    {
        $order = new Order(
            1,
            'PHP Book',
            'PHP Book',
            1000,
            1,
            'shipping_ready',
            new DateTimeImmutable('2024-01-01')
        );

        $this->assertFalse($order->isCancelable(new DateTimeImmutable('2023-12-31')));
    }

    /**
     * 注文日当日はキャンセル可能
     */
    public function testIsCancelableOnOrderDate()
    {
        $order = new Order(
            1,
            'PHP Book',
            'PHP Book',
            1000,
            1,
            'shipping_ready',
            new DateTimeImmutable('2024-01-01')
        );

        $this->assertTrue($order->isCancelable(new DateTimeImmutable('2024-01-01')));
    }

    /**
     * 注文日の翌日はキャンセル可能
     */
    public function testIsCancelableAfterOrderDate()
    {
        $order = new Order(
            1,
            'PHP Book',
            'PHP Book',
            1000,
            1,
            'shipping_ready',
            new DateTimeImmutable('2024-01-01')
        );

        $this->assertTrue($order->isCancelable(new DateTimeImmutable('2024-01-02')));
    }

    /**
     * 注文日から3日後の1日前はキャンセル可能
     */
    public function testIsCancelableBefore3Days()
    {
        $order = new Order(
            1,
            'PHP Book',
            'PHP Book',
            1000,
            1,
            'shipping_ready',
            new DateTimeImmutable('2024-01-01')
        );

        $this->assertTrue($order->isCancelable(new DateTimeImmutable('2024-01-03')));
    }

    /**
     * 注文日から3日後の当日はキャンセル可能
     */
    public function testIsCancelableOn3Days()
    {
        $order = new Order(
            1,
            'PHP Book',
            'PHP Book',
            1000,
            1,
            'shipping_ready',
            new DateTimeImmutable('2024-01-01')
        );

        $this->assertTrue($order->isCancelable(new DateTimeImmutable('2024-01-04')));
    }

    /**
     * 注文日から3日後の1日後はキャンセル不可
     */
    public function testIsCancelableAfter3Days()
    {
        $order = new Order(
            1,
            'PHP Book',
            'PHP Book',
            1000,
            1,
            'shipping_ready',
            new DateTimeImmutable('2024-01-01')
        );

        $this->assertFalse($order->isCancelable(new DateTimeImmutable('2024-01-05')));
    }

```

テスト件数は多くなりますが、問題が発生しやすい境界値付近が正常に動作しているかを評価することができます。

## デシジョンテーブル(Decision Table)
デシジョンテーブルは、入力値の組み合わせとその期待結果を整理してテストするテスト技法です。
注文金額計算メソッドに対するデシジョンテーブルを次のように作成しました。

| 注文日の状態     | 商品名     | 注文量    | 注文料金計算                                |
|----------------|------------|----------|-------------------------------------------|
| キャンペーン期間中 | 一般商品    | 10個未満  | 基本価格 - キャンペーン割引                      |
| キャンペーン期間中 | 一般商品    | 10個以上  | 基本価格 - キャンペーン割引 - 大量注文割引            |
| キャンペーン期間中 | 限定商品    | 10個未満  | 基本価格 - 限定商品割引 - キャンペーン割引            |
| キャンペーン期間中 | 限定商品    | 10個以上  | 基本価格 - 限定商品割引 - キャンペーン割引 - 大量注文割引  |
| キャンペーン期間外 | 一般商品    | 10個未満  | 基本価格                                      |
| キャンペーン期間外 | 一般商品    | 10個以上  | 基本価格 - 大量注文割引                            |
| キャンペーン期間外 | 限定商品    | 10個未満  | 基本価格 - 限定商品割引                            |
| キャンペーン期間外 | 限定商品    | 10個以上  | 基本価格 - 限定商品割引 - 大量注文割引                  |

注文金額計算メソッドは次のように定義されています。

```php
    /**
     * 注文料金を計算する
     *
     * @param array<string, DateTimeImmutable> $campaignPeriod
     */
    public function calculatePrice(
        array $campaignPeriod
    ): int
    {
        $price = $this->price * $this->quantity;

        // キャンペーン期間中は10%割引
        if ($campaignPeriod['start'] <= $this->orderDate && $this->orderDate <= $campaignPeriod['end']) {
            $price = $price * 0.9;
        }

        // 限定商品は20%割引
        if ($this->isLimited()) {
            $price = $price * 0.8;
        }

        // 注文数が10以上なら5%割引
        if ($this->quantity >= 10) {
            $price = $price * 0.95;
        }

        // 端数は切り捨て
        return (int)floor($price);
    }
```

全てのテストコードを示すと長くなるため、一部を示します。
複数の入力条件の組み合わせがある場合、視認性を考慮して日本語でテストを記述することがあります。

```php
    /**
     * キャンペーン期間中かつ、限定商品かつ、10個以上注文の場合
     */
    public function test_キャンペーン期間中かつ限定商品かつ10個以上注文の場合()
    {
        $order = new Order(
            1,
            '限定商品',
            '限定商品',
            1000,
            10,
            'shipping_ready',
            new DateTimeImmutable('2024-01-01')
        );

        $campaignPeriod = [
            'start' => new DateTimeImmutable('2023-12-31'),
            'end' => new DateTimeImmutable('2024-01-02'),
        ];

        $this->assertSame(6840, $order->calculatePrice($campaignPeriod));
    }
```

デシジョンテーブルを作成することで、考慮漏れや重複を防ぎながら網羅的にテストすることが可能です。
複雑なビジネスルールがある場合に特に有効です。

## 状態遷移テスト(State Transition Testing)

状態遷移テストは、ソフトウェアの状態の遷移を整理し、状態遷移を網羅してテストする技法です。
テスト対象の状態の変化に注目してテストを行います。

```mermaid
stateDiagram-v2
    [*] --> 発送準備
    発送準備 --> 発送完了: 発送処理完了
    発送準備 --> キャンセル: キャンセル処理
    発送完了 --> [*]
    キャンセル --> [*]
```

状態遷移図は注文商品のライフサイクルを示しています。
- 発送準備：この状態は、注文が確定し、商品が発送の準備段階にあることを意味します。初期状態からこの状態に遷移します。
- 発送完了：商品の発送準備が完了し、実際に発送された状態です。ここからは他の状態への遷移は発生しません。
- キャンセル：注文がキャンセルされた状態です。こちらも発送完了と同様に、他の状態への遷移はありません。

ここで定義されたメソッドに対してテストを行います。

```php
    /**
     * キャンセル処理
     */
    public function cancel(): Order
    {
        // 発送準備中以外はキャンセル不可
        if ($this->status !== 'shipping_ready') {
            throw new Exception('キャンセル不可');
        }

        // キャンセル処理
        return new Order(
            $this->id,
            $this->name,
            $this->description,
            $this->price,
            $this->quantity,
            'canceled',
            $this->orderDate,
        );
    }

    /**
     * 発送処理
     */
    public function ship(): Order
    {
        // 発送準備中以外は発送不可
        if ($this->status !== 'shipping_ready') {
            throw new Exception('発送不可');
        }

        // 発送処理
        return new Order(
            $this->id,
            $this->name,
            $this->description,
            $this->price,
            $this->quantity,
            'shipped',
            $this->orderDate,
        );
    }

```

次がテストケース例です。

```php
    /**
     * キャンセル処理
     */
    public function testCancel()
    {
        $order = new Order(
            1,
            'PHP Book',
            'PHP Book',
            1000,
            1,
            'shipping_ready',
            new DateTimeImmutable('2024-01-01')
        );

        $canceledOrder = $order->cancel();

        $this->assertTrue($canceledOrder->isCanceled());
    }

    /**
     * 出荷処理
     */
    public function testShip()
    {
        $order = new Order(
            1,
            'PHP Book',
            'PHP Book',
            1000,
            1,
            'shipping_ready',
            new DateTimeImmutable('2024-01-01')
        );

        $shippedOrder = $order->ship();

        $this->assertTrue($shippedOrder->isShipped());
    }

    /**
     * 出荷済みの場合は出荷処理不可
     */
    public function testShipWhenAlreadyShipped()
    {
        $order = new Order(
            1,
            'PHP Book',
            'PHP Book',
            1000,
            1,
            'shipped',
            new DateTimeImmutable('2024-01-01')
        );

        $this->expectException(Exception::class);
        $order->ship();
    }

    /**
     * キャンセル済みの場合は出荷処理不可
     */
    public function testShipWhenAlreadyCanceled()
    {
        $order = new Order(
            1,
            'PHP Book',
            'PHP Book',
            1000,
            1,
            'canceled',
            new DateTimeImmutable('2024-01-01')
        );

        $this->expectException(Exception::class);
        $order->ship();
    }
```

状態遷移図に基づいてテストケースを設計することで、各状態と遷移を適切にカバーすることができます。
また、各状態から適切な状態への遷移が正しく行われるか、不適切な状態への遷移が防がれているかを評価することができます。

## まとめ
この記事では、PHPUnitを用いたブラックボックステスト技法に焦点を当て、具体的な注文商品クラスを例に挙げながら、同値分割法、境界値分析、デシジョンテーブル、状態遷移テストという異なるテスト技法を解説しました。

- 同値分割法は、異なる入力値を代表するグループに分けて効率的にテストします。
- 境界値分析は、入力値の境界付近に焦点を当て、バグの発生しやすい箇所を特定します。
- デシジョンテーブルは、複数の入力値とそれらの期待される出力を整理し、複雑なビジネスルールのテストに適しています。
- 状態遷移テストは、システムの状態遷移に着目し、状態間の動作を正確に捉えるために役立ちます。

これらのテスト技法を駆使することで、効率的にテストを行うことが可能になり、システムの品質向上につながると思います。
また、他にも様々なテスト技法があると思いますので、ぜひ調べてみてください。

## 参考書籍
https://gihyo.jp/magazine/SD/archive/2024/202402
