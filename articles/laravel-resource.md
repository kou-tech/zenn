---
title: "Laravel API Resourceの活用とスキーマ駆動開発における注意点"
emoji: "🦌"
type: "tech" # tech: 技術記事 / idea: アイデア
topics: ["Laravel", "PHP"]
published: true
---

## はじめに

本記事では、Laravel API Resources の基本的な使い方と、スキーマ駆動開発における注意点を解説する。私は Scramble のような静的解析ベースの OpenAPI 生成ツールを活用しており、その観点から動的プロパティの問題点と推奨するアプローチについても述べる。

## Laravel API Resources とは

Laravel API Resources は、Eloquent モデルを JSON レスポンスに変換するための機能である。API を構築する際、モデルのデータをそのまま返すのではなく、クライアントに必要な形式に整形して返すことができる。

Laravel 12 では、`php artisan make:resource` コマンドでリソースクラスを生成できる。

```bash
php artisan make:resource UserResource
```

生成されたリソースクラスは `app/Http/Resources` ディレクトリに配置され、`Illuminate\Http\Resources\Json\JsonResource` を継承する。

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

## なぜ API Resources を使うのか

API Resources を使用する主な理由は以下の通りである。

1. プロジェクト全体で一貫したJSON構造を維持できる
2. クライアントに返すフィールドを明示的に指定できる
3. モデルとAPIレスポンスの変換ロジックを分離できる
4. 同じリソースクラスを複数のエンドポイントで使用できる

## API Resources を使わないことで起きる問題

### 不要なデータが漏れる

Eloquent モデルをそのまま返すと、意図せずセンシティブな情報が漏れる可能性がある。

```php
// 危険な例
Route::get('/users/{id}', function (string $id) {
    return User::findOrFail($id);
});
```

この場合、`password`、`remember_token`、`email_verified_at` など、クライアントに公開すべきでない情報も含まれてしまう。`$hidden` プロパティで制御することも可能だが、API によって公開したいフィールドが異なる場合に対応できない。

### レスポンス形式がバラバラになる

リソースクラスを使用しない場合、開発者ごとに異なるレスポンス形式になりやすい。

```php
// 開発者Aのコード
return response()->json([
    'user' => $user,
    'status' => 'success'
]);

// 開発者Bのコード
return response()->json([
    'data' => $user,
    'code' => 200
]);
```

このような不統一は、フロントエンドの実装を複雑にし、バグの温床となる。

### Controller が肥大化する

変換ロジックを Controller に記述すると、コードが肥大化する。

```php
// 肥大化した Controller の例
public function show(string $id)
{
    $user = User::with('posts', 'profile')->findOrFail($id);

    return response()->json([
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'profile' => [
            'bio' => $user->profile->bio,
            'avatar_url' => $user->profile->avatar_url,
        ],
        'posts' => $user->posts->map(function ($post) {
            return [
                'id' => $post->id,
                'title' => $post->title,
                'excerpt' => Str::limit($post->body, 100),
            ];
        }),
    ]);
}
```

## API Resource の使い方

### 単一のデータを返す場合

リソースクラスのインスタンスを作成し、モデルを渡すことで変換される。

```php
use App\Http\Resources\UserResource;
use App\Models\User;

Route::get('/user/{id}', function (string $id) {
    return new UserResource(User::findOrFail($id));
});
```

Laravel 12 では、モデルに対して直接 `toResource()` メソッドを呼び出すこともできる。このメソッドは規約に基づいてリソースクラスを自動発見する（例: `User` モデル → `UserResource`）。

```php
Route::get('/user/{id}', function (string $id) {
    return User::findOrFail($id)->toResource();
});
```

レスポンスは以下の形式になる。

```json
{
    "data": {
        "id": 1,
        "name": "山田太郎",
        "email": "yamada@example.com",
        "created_at": "2024-01-01T00:00:00.000000Z",
        "updated_at": "2024-01-01T00:00:00.000000Z"
    }
}
```

### 複数のデータを返す場合

複数のモデルを返す場合、`collection` メソッドを使用する方法と、カスタム ResourceCollection クラスを作成する方法がある。

#### collection メソッドを使う

最もシンプルな方法は、リソースクラスの `collection` メソッドを使用することである。

```php
use App\Http\Resources\UserResource;
use App\Models\User;

Route::get('/users', function () {
    return UserResource::collection(User::all());
});
```

#### ResourceCollection クラスを作成する

メタデータを追加したい場合やコレクション固有のロジックが必要な場合は、専用の ResourceCollection クラスを作成する。

```bash
php artisan make:resource UserCollection
```

```php
class UserCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'statistics' => [
                'total_count' => $this->collection->count(),
                'active_count' => $this->collection->where('status', 'active')->count(),
                'inactive_count' => $this->collection->where('status', 'inactive')->count(),
            ],
        ];
    }
}
```

使用方法は以下の通りである。

```php
use App\Http\Resources\UserCollection;
use App\Models\User;

Route::get('/users', function () {
    return new UserCollection(User::all());
});
```

#### ページネーションとの組み合わせ

ページネーションを使用する場合、リソースは自動的にページネーション情報を含める。

```php
Route::get('/users', function () {
    return UserResource::collection(User::paginate());
});
```

レスポンスには `data`、`links`、`meta` が含まれる。

```json
{
    "data": [
        {
            "id": 1,
            "name": "山田太郎",
            "email": "yamada@example.com"
        }
    ],
    "links": {
        "first": "http://example.com/users?page=1",
        "last": "http://example.com/users?page=5",
        "prev": null,
        "next": "http://example.com/users?page=2"
    },
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 5,
        "path": "http://example.com/users",
        "per_page": 15,
        "to": 15,
        "total": 75
    }
}
```

### リソースクラスを組み合わせる方法

リレーションを持つモデルの場合、他のリソースクラスをネストして使用できる。

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'author' => new UserResource($this->user),
            'created_at' => $this->created_at,
        ];
    }
}
```

## スキーマ駆動開発と動的プロパティの問題

### OpenAPI 生成ツールとの相性

スキーマ駆動開発とは、OpenAPI（旧 Swagger）仕様のスキーマを起点として API を設計・実装する手法である。スキーマからクライアントコードやドキュメントを自動生成できるため、フロントエンドとバックエンドの連携がスムーズになる。

Laravel では、Scramble のような静的解析ベースの OpenAPI 生成ツールが利用できる。Scramble はコードを解析して自動的に OpenAPI スキーマを生成するため、PHPDoc を手動で書く必要がない。しかし、API Resource の動的プロパティが問題になる場合がある。

Laravel の API Resource には、条件に応じてプロパティの有無を切り替える機能がある。

```php
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'email' => $this->email,
        'secret' => $this->when($request->user()->isAdmin(), $this->secret_field),
        'posts' => PostResource::collection($this->whenLoaded('posts')),
    ];
}
```

このコードでは、`secret` は管理者のみ、`posts` はリレーションがロードされている場合のみレスポンスに含まれる。

### Scramble での扱い

Scramble は静的解析によってこれらの条件付きフィールドを検出し、OpenAPI スキーマで **optional（任意）** としてマークする。

```yaml
# 生成される OpenAPI スキーマのイメージ
UserResource:
  type: object
  required:
    - id
    - name
    - email
  properties:
    id:
      type: integer
    name:
      type: string
    email:
      type: string
    secret:
      type: string  # optional
    posts:
      type: array   # optional
      items:
        $ref: '#/components/schemas/PostResource'
```

### 動的プロパティの問題点

スキーマ駆動開発において、動的プロパティには以下の問題がある。

#### スキーマの曖昧さ

フロントエンド開発者がスキーマを見たとき、optional なフィールドがどのような条件で含まれるのかがわからない。「管理者のみ」なのか「リレーションがロードされている場合」なのか、スキーマからは判断できない。

#### 型安全性の低下

TypeScript などで型を生成する場合、optional なフィールドは常に `undefined` の可能性を考慮する必要があり、コードが煩雑になる。

```typescript
// 動的プロパティがある場合
interface User {
  id: number;
  name: string;
  email: string;
  secret?: string;  // 常に undefined チェックが必要
  posts?: Post[];   // 常に undefined チェックが必要
}
```

#### API の予測可能性の低下

同じエンドポイントでもリクエストの状況によってレスポンス構造が変わるため、デバッグやテストが複雑になる。

### 推奨するアプローチ

スキーマ駆動開発では、動的プロパティの使用を最小限に抑え、明確なスキーマを維持することが望ましいと考える。以下に具体的なアプローチを示す。

#### 専用のエンドポイントを分ける

権限によってレスポンスが大きく異なる場合は、エンドポイント自体を分ける。

```php
// 一般ユーザー向け
Route::get('/users/{id}', [UserController::class, 'show']);

// 管理者向け（追加情報を含む）
Route::get('/admin/users/{id}', [AdminUserController::class, 'show']);
```

#### 専用のリソースクラスを作成する

ユースケースごとに異なるリソースクラスを用意する。

```php
// 基本情報のみ
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}

// 詳細情報を含む（管理者向け）
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'secret_field' => $this->secret_field,
            'internal_notes' => $this->internal_notes,
        ];
    }
}

// リレーションを含む（詳細ページ用）
class UserDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'posts' => PostResource::collection($this->posts),
            'profile' => new ProfileResource($this->profile),
        ];
    }
}
```

#### リレーションは常にロードする

`whenLoaded` を使わず、Controller で必要なリレーションを常にロードする。

```php
// Controller
public function show(string $id)
{
    $user = User::with(['posts', 'profile'])->findOrFail($id);
    return new UserDetailResource($user);
}

// Resource - whenLoaded を使わない
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'posts' => PostResource::collection($this->posts),
        'profile' => new ProfileResource($this->profile),
    ];
}
```

## API Resourcesの使用上の注意

### ビジネスロジックを記述しない

リソースクラスはデータの変換に特化すべきである。ビジネスロジックや複雑な計算処理を記述すると、責務が曖昧になる。

```php
// 悪い例
public function toArray(Request $request): array
{
    // リソース内でデータベースクエリを実行している
    $relatedUsers = User::where('department_id', $this->department_id)->get();

    return [
        'id' => $this->id,
        'name' => $this->name,
        'colleagues' => UserResource::collection($relatedUsers),
    ];
}
```

```php
// 良い例 - Controller でリレーションをロードしておく
// Controller
$user = User::with('colleagues')->findOrFail($id);
return new UserResource($user);

// Resource
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'colleagues' => UserResource::collection($this->colleagues),
    ];
}
```

### Resource に書いてよいもの・書いてはいけないもの

個人的な意見として、以下のように整理する。

| 書いてよいもの | 書いてはいけないもの |
|----------------|----------------------|
| データのフォーマット変換（日付の形式変更など） | 金額の計算・集計 |
| 表示用の文字列結合（姓 + 名など） | 条件に基づく複雑な判断 |
| 条件付きでデータを含める/含めない | データベースへのアクセス（保存・更新） |
| リレーションデータの変換 | 外部APIとの通信 |
| | ソート・フィルター（Controller/クエリで行う） |

## まとめ

Laravel API Resources は、API レスポンスを整形するための強力な機能である。適切に使用することで、以下のメリットが得られる。

- セキュリティの向上（不要なデータの漏洩防止）
- コードの可読性と保守性の向上
- レスポンス形式の統一
- 関心の分離による責務の明確化

一方で、Scramble のような静的解析ベースの OpenAPI 生成ツールを活用したスキーマ駆動開発では、`when` や `whenLoaded` などの動的プロパティがスキーマの曖昧さにつながる可能性がある。

個人的には、スキーマ駆動開発を採用している場合は以下のアプローチを推奨する。

- 動的プロパティの使用を最小限に抑える
- ユースケースごとに専用のリソースクラスを作成する
- 権限によってレスポンスが異なる場合はエンドポイントを分ける

リソースクラスが増えることへの懸念もあるかもしれないが、スキーマの明確さと型安全性のメリットの方が大きいと考える。プロジェクトの要件や開発スタイルに応じて、適切なバランスを見つけてほしい。

## 参考

- [Laravel 12.x Eloquent: API Resources](https://laravel.com/docs/12.x/eloquent-resources)
- [Scramble - OpenAPI Documentation Generator for Laravel](https://scramble.dedoc.co/)
