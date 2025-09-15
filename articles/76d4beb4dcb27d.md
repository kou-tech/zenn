---
title: "あなたのLaravelアプリは大丈夫？マスアサインメント保護の落とし穴"
emoji: "🦌"
type: "tech" # tech: 技術記事 / idea: アイデア
topics: ["php", "Laravel"]
published: true
---

## はじめに

Laravelのマスアサインメント保護機能は、多くの開発者にとって「設定しておけば安全」という認識で使われがちである。
確かに、この機能はWebアプリケーションの代表的な脆弱性の一つ「マスアサインメント脆弱性」を防ぐ重要な役割を果たす。

しかし、単に`$fillable`や`$guarded`を設定するだけで本当に安全と言えるだろうか。
実際の開発現場では、これらの設定があるにも関わらず、意図しないデータの更新が発生するケースを目にすることがある。

本記事では、Laravel 12を使用して、マスアサインメント保護機能の仕組みを理解し、よくある誤用パターンとその対策について解説する。
特に、**マスアサインメント保護が効かないメソッド**について詳しく説明する。

## マスアサインメントとは何か

### 基本的な仕組み

マスアサインメントとは、配列やリクエストデータを使って複数の属性を一度に設定する機能である。
Laravel公式ドキュメント（[Mass Assignment - Laravel 12.x](https://laravel.com/docs/12.x/eloquent#mass-assignment)）によると、この機能は開発効率を大幅に向上させる一方で、適切な保護なしには重大なセキュリティリスクとなる。

```php
// マスアサインメントを使った例
$user = User::create($request->all());

// マスアサインメントを使わない例
$user = new User();
$user->name = $request->input('name');
$user->email = $request->input('email');
$user->save();
```

### なぜ危険なのか

OWASP（Open Web Application Security Project）では、マスアサインメント脆弱性を「API Security Top 10 2019」の一つとして挙げている（[OWASP API6:2019 Mass Assignment](https://owasp.org/API-Security/editions/2019/en/0xa6-mass-assignment/)）。

以下のUsersテーブルを例に考えてみる。

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email');
    $table->string('password');
    $table->boolean('is_admin')->default(false);
    $table->decimal('balance', 10, 2)->default(0);
    $table->timestamps();
});
```

保護機能なしでマスアサインメントを使用した場合、以下の結果となる。

```php
// Userモデル（保護なし - 危険）
class User extends Model
{
    // 保護設定なし
}

// コントローラー
public function store(Request $request)
{
    $user = User::create($request->all());
    return response()->json($user);
}
```

このとき、悪意のあるユーザーが以下のようなリクエストを送信できる。

```bash
curl -X POST http://example.com/api/users \
  -H "Content-Type: application/json" \
  -d '{
    "name": "攻撃者",
    "email": "attacker@example.com",
    "password": "password123",
    "is_admin": true,
    "balance": 1000000
  }'
```

## Laravelの保護機能

### $fillableプロパティ (ホワイトリスト方式)

```php
class User extends Model
{
    protected $fillable = [
        'name',
        'email',
        'password'
    ];
}
```

`$fillable`に指定されたフィールドのみがマスアサインメント可能となる。

### $guardedプロパティ (ブラックリスト方式)

```php
class User extends Model
{
    protected $guarded = [
        'is_admin',
        'balance',
        'id'
    ];
}
```

## マスアサインメント保護が効かないEloquentメソッド
ただし、すべてのメソッドがマスアサインメント保護の対象となるわけではない。
以下、保護が効くメソッドと効かないメソッドを詳細に検証する。

### 保護が効くメソッド例

```php
class User extends Model
{
    protected $fillable = [
        'name',
        'email',
        'password'
    ];
}

// 1. create() - 保護される
$user = User::create([
    'name' => 'John',
    'is_admin' => true  // 無視される
]);

// 2. fill() - 保護される
$user = new User();
$user->fill([
    'name' => 'John',
    'is_admin' => true  // 無視される
]);

// 3. update() - 保護される
$user->update([
    'name' => 'Jane',
    'is_admin' => true  // 無視される
]);

```

### 保護が効かないメソッド例

```php
// 1. 直接プロパティ代入
$user = new User();
$user->name = 'John';
$user->is_admin = true;  // 設定される！
$user->save();

// 2. forceFill()
$user = new User();
$user->forceFill([
    'name' => 'John',
    'is_admin' => true  // 設定される！
]);
$user->save();

// 3. forceCreate()
$user = User::forceCreate([
    'name' => 'John',
    'email' => 'john@example.com',
    'password' => bcrypt('password'),
    'is_admin' => true  // 設定される！
]);

// 4. insert()
User::insert([
    'name' => 'John',
    'email' => 'john@example.com',
    'password' => bcrypt('password'),
    'is_admin' => true,  // 設定される！
    'created_at' => now(),
    'updated_at' => now()
]);

// 5. DB::table()
DB::table('users')->insert([
    'name' => 'John',
    'email' => 'john@example.com',
    'password' => bcrypt('password'),
    'is_admin' => true,  // 設定される！
    'created_at' => now(),
    'updated_at' => now()
]);

// 6. Query Builderのupdate()
User::where('id', 1)->update($request->all());  // 保護されない！全フィールドが更新可能

```

## 推奨事項
マスアサインメント保護を効果的に実装するために、重要な推奨事項をいくつか紹介する。

### 1. 原則として$fillableを使用する
個人的な意見として、`$guarded`よりも`$fillable`の使用を推奨する。
ホワイトリスト方式の方が、新しいフィールドを追加した際にデフォルトで保護される点で安全性が高い。

```php
// 推奨：明示的に許可するフィールドを指定
protected $fillable = ['name', 'email', 'password'];
```

### 2. 開発環境でpreventSilentlyDiscardingAttributes()を有効化
`preventSilentlyDiscardingAttributes()`メソッドは、マスアサインメント保護によって無視されるはずの属性が含まれていた場合に、例外を投げる機能である。
開発段階で問題を早期発見するため、有効化しておく。

[Laravel Documentation](https://laravel.com/docs/12.x/eloquent#configuring-eloquent-strictness)

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    if (app()->environment('local', 'testing')) {
        Model::preventSilentlyDiscardingAttributes();
    }
}

// コントローラー
$user = User::create([
    'name' => 'John',
    'email' => 'john@example.com',
    'is_admin' => true  // 例外が発生！
]);
```

### 3. 安全なメソッドのみを使用する

```php
// 推奨：常にcreate/update/fillを使用
$user = User::create($request->validated());
$user->update($request->validated());

// 非推奨：直接代入やforce系メソッド
$user->is_admin = $request->is_admin;  // 危険
$user->forceFill($request->all());      // 危険
```

### 4. テストで保護を検証する

```php
public function test_mass_assignment_protection_with_various_methods()
{
    $dangerousData = [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'is_admin' => true,
        'balance' => 99999
    ];
    
    // create()メソッドのテスト
    $user1 = User::create($dangerousData);
    $this->assertFalse($user1->is_admin);
    $this->assertEquals(0, $user1->balance);
    
    // forceCreate()は使用禁止とする
    $this->expectException(\BadMethodCallException::class);
    User::forceCreate($dangerousData);
}

public function test_query_builder_is_not_allowed_for_user_creation()
{
    // Query Builderの直接使用を禁止
    $this->expectException(\RuntimeException::class);
    DB::table('users')->insert([
        'name' => 'Test',
        'is_admin' => true
    ]);
}
```

## まとめ

マスアサインメント保護は、Laravelが提供する重要なセキュリティ機能である。
しかし、本記事で明らかにしたように、**すべてのEloquentメソッドが保護の対象となるわけではない**。

「`$fillable`を設定したから安全」という過信は禁物である。本記事で示した「保護が効かないメソッド」の存在を常に意識し、適切なメソッドを選択することが、セキュアなLaravelアプリケーション開発へと繋がると考える。
