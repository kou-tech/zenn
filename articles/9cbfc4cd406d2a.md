---
title: "Laravelにおける権限管理のプラクティス"
emoji: "🦌"
type: "tech" # tech: 技術記事 / idea: アイデア
topics: ["php", "laravel"]
published: true
---

## 前書き

システムを構築するにあたって、権限管理はほとんどのケースにおいて必須となってくることが多い。
掲示板サイト1つを例にとっても、投稿を編集、削除、投稿に対してコメントできるなど様々なケースにおいて、検討すべきケースが多い。

また、権限のルールも複雑になってくることが多い。
例えば、投稿から1日以内であれば削除できる。管理者であれば削除できる、所属している事業所が同じであれば、削除できる。所属が同じかつマネージャーであれば、など様々なシナリオがある。

そういった場合のLaravelでは、どのようなプラクティスで解消できるかをこの記事では説明する。
## アクセス制御の基本概念

権限の話題をすると上がってくるのが、RBAC(Role Base Access Control)とABAC(Attribute Base Access Control)、PBAC(Policy Base Access Control)の主な3つのアクセス制御である。その他にもさまざまなアクセス制御の考えがあると思うが、ここでは省略する。

### RBAC（Role-Based Access Control）

RBACとは、ロールベースのアクセス制御である。ユーザーに役割（ロール）を割り当て、そのロールに対して権限（permission）を付与することで権限管理を行う仕組みである。

比較的シンプルな権限管理を行う場合にあたって、とても有効である。ロールに付与されている権限を変更することで、影響する箇所の修正コストが減る。ただし、複雑な条件における権限管理には適さない。

RBACの利点は組織構造に合わせた権限設計が可能である点だ。
従来の「管理者」「一般ユーザー」といった単純な区分から脱却し、「マネージャー」「編集者」「閲覧者」など、より現実的な役割分担を反映できる。

### ABAC（Attribute-Based Access Control）

ABACは、ユーザーの属性に応じたアクセス制御である。所属部署が同じであれば、操作できるといった制御を指す。
こちらは複雑な権限管理に対応することができる。ただし、権限のメンテナンスコストが大きいため、採用する際はよく検討する必要がある。

ABACでは、ユーザーの属性（部署、役職、経験年数）、リソースの属性（作成者、部署、機密レベル）、環境の属性（時間、IPアドレス、デバイス）など、多様な要素を組み合わせて権限判定を行う。
これにより「同じ部署のマネージャーのみ編集可能」といった柔軟なルールを実現できる。

### PBAC（Policy-Based Access Control）

PBACは、システムやプラットフォームのポリシーに応じたアクセス制御である。
例えば、投稿は1日以内であれば削除できる、夜間は操作できないといったケースに適合する。

PBACは時間や状況に依存する権限管理に特化している。ビジネスルールやコンプライアンス要件を直接コードに反映でき、動的な権限制御を実現する。

---

ここで各アクセス制御について解説したが、重要なのは単一のアクセス制御方式に固執するのではなく、要件に応じて適切な制御方式を選択することである。
実際のシステム開発では、RBACとPBACを組み合わせたハイブリッドなアクセス制御を採用することが多い。
例えば、AWSのIAMポリシーもロールベースの制御と属性ベースの制御を組み合わせた仕組みを提供している。
このように、複数のアクセス制御方式を組み合わせることで、柔軟かつ効果的な権限管理を実現できる。

## Laravelにおける権限管理の選択肢

Laravelでは権限管理に対して複数のアプローチが用意されている。標準機能であるGateとPolicy、そして外部パッケージであるLaravel Permissionが主要な選択肢である。

### Gate/Policy

#### Gate

Gateは、Laravelが提供する最もシンプルな権限管理機能である。クロージャベースで権限ロジックを定義し、主にABACやPBACのアプローチに適している。

```php
// AuthServiceProvider.php
Gate::define('edit-post', function ($user, $post) {
    // 作成者本人か、同じ部署のマネージャー以上
    return $user->id === $post->user_id || 
           ($user->department === $post->author->department && 
            in_array($user->role, ['manager', 'director'], true));
});

Gate::define('delete-post', function ($user, $post) {
    // 1日以内かつ作成者本人、または管理者
    return ($post->created_at->diffInDays() <= 1 && $user->id === $post->user_id) ||
           $user->hasRole('admin');
});
```

Gateの利点は、複雑なビジネスロジックを直接記述できることである。時間的制約、属性比較、外部APIとの連携など、あらゆる条件を組み込める。
反面、権限が増加すると管理が困難になり、テストも複雑化する傾向がある。

#### Policy

PolicyはEloquentモデルに特化した権限管理機能である。
RESTfulな操作（`view`、`create`、`update`、`delete`）に対応した構造化されたアプローチを提供する。

```php
// PostPolicy.php
class PostPolicy
{
    public function view(User $user, Post $post)
    {
        // 公開済みか、作成者本人か、同部署の役員
        return $post->status === 'published' ||
               $user->id === $post->user_id ||
               ($user->department === $post->author->department && $user->role === 'director');
    }

    public function update(User $user, Post $post)
    {
        return $user->id === $post->user_id ||
               ($user->department === $post->author->department && 
                in_array($user->role, ['manager', 'director']));
    }

    public function delete(User $user, Post $post)
    {
        return $user->hasRole('admin') ||
               ($user->id === $post->user_id && $post->created_at->diffInDays() <= 1);
    }
}
```

Policyの強みは、モデル中心の整理された構造である。各モデルに対する権限ロジックが明確に分離され、可読性と保守性が向上する。

### Laravel Permission

Laravel Permissionは、Spatie社が開発したRBAC実装に特化したパッケージである。
データベースベースの権限管理を提供し、ロールと権限の階層構造を効率的に管理できる。

```php
// ロールと権限の定義
$role = Role::create(['name' => 'marketing-manager']);
$permission = Permission::create(['name' => 'edit posts']);

$role->givePermissionTo($permission);
$user->assignRole('marketing-manager');

// 権限チェック
if ($user->can('edit posts')) {
    // 編集権限あり
}

if ($user->hasRole('marketing-manager')) {
    // マーケティングマネージャーの権限あり
}
```

### Gate/Policyとの違い

Laravel PermissionとGate/Policyの最大の違いは、権限管理のアプローチである。

**データ管理の観点**
- Gate/Policy：コードベースで権限ロジックを管理
- Laravel Permission：データベースベースで権限情報を管理

**柔軟性の観点**
- Gate/Policy：複雑な条件分岐やビジネスロジックに対応
- Laravel Permission：ロールと権限の組み合わせに特化

**運用の観点**
- Gate/Policy：権限変更時にコード修正とデプロイが必要
- Laravel Permission：管理画面から動的に権限変更が可能

私の経験上、小規模なアプリケーションや複雑な条件判定が必要な場合はGate/Policyが適している。一方、中規模以上で組織的な権限管理が必要な場合は、Laravel Permissionの方が運用効率が高い。

### 自前で実装しない理由

権限管理を自前で実装することは技術的には可能だが、以下の理由から私はLaravel Permissionを利用することが多い。

**セキュリティリスク**
権限管理はセキュリティの要であり、実装ミスが直接的な脆弱性につながる。既存のパッケージは多くの開発者によってテストされ、セキュリティホールが修正されていることが多い。

**保守性の問題**
自前実装の権限システムは、要件変更や機能追加時の修正コストが高い。特に、権限階層の変更や新しいアクセス制御方式の導入時に大幅な修正が必要になる。

**テストの複雑化**
権限管理のテストは組み合わせパターンが膨大になり、自前実装では漏れが生じやすい。既存パッケージには包括的なテストスイートが含まれている。これらの理由から、特別な要件がない限りは、実績のあるパッケージを選択することが賢明と考える。

## 具体的なシナリオ

実際のシステムで権限管理がどのように実装されるかを、企業ブログシステムを例に比較検討する。

### 想定される要件

| 項目 | 詳細 |
|------|------|
| **組織構造** | |
| 部署 | マーケティング、エンジニアリング、人事 |
| 役職レベル | 一般、主任、マネージャー、役員 |
| 記事の状態 | 下書き、レビュー中、公開済み |
| **権限ルール** | |
| 記事作成 | 全ての認証ユーザー |
| 記事編集 | 作成者本人 + 同部署のマネージャー以上 |
| 記事公開 | マネージャー以上のみ |
| 他部署記事閲覧 | 役員のみ |
| 分析データ閲覧 | マーケティング部 + 役員 |
| ユーザー管理 | 人事部のマネージャー以上 |

### Gate/Policyでの実装

```php
// AuthServiceProvider.php
Gate::define('create-article', function ($user) {
    return $user !== null; // 認証済みユーザー
});

Gate::define('edit-article', function ($user, $article) {
    // 作成者本人または同部署のマネージャー以上
    return $user->id === $article->user_id || 
           ($user->department === $article->author->department && 
            in_array($user->role, ['manager', 'director']));
});

Gate::define('publish-article', function ($user) {
    return in_array($user->role, ['manager', 'director']);
});

Gate::define('view-other-department-articles', function ($user) {
    return $user->role === 'director';
});

Gate::define('view-analytics', function ($user) {
    return $user->department === 'marketing' || $user->role === 'director';
});

Gate::define('manage-users', function ($user) {
    return $user->department === 'hr' && 
           in_array($user->role, ['manager', 'director']);
});
```

**課題と問題点**
1. 関連する権限ルールが複数のGate定義に分散し、全体像の把握が困難
2. 役職チェックのロジックが各定義で重複
3. 各Gate定義に対する個別テストが必要
4. 新しい部署や役職の追加時にコード修正が必要

### Laravel Permissionでの実装

```php
// データベースでのロール・権限定義
$departments = ['marketing', 'engineering', 'hr'];
$roles = ['staff', 'senior', 'manager', 'director'];

foreach ($departments as $dept) {
    foreach ($roles as $role) {
        Role::create(['name' => "{$dept}-{$role}"]);
    }
}

// 権限の定義
$permissions = [
    'create articles',
    'edit own articles',
    'edit department articles',
    'publish articles',
    'view other department articles',
    'view analytics',
    'manage users'
];

foreach ($permissions as $permission) {
    Permission::create(['name' => $permission]);
}

// 権限の割り当て
// 全員が記事作成可能
Role::all()->each(function ($role) {
    $role->givePermissionTo('create articles', 'edit own articles');
});

// マネージャー以上は部署内記事編集・公開可能
Role::where('name', 'like', '%-manager')
    ->orWhere('name', 'like', '%-director')
    ->get()
    ->each(function ($role) {
        $role->givePermissionTo('edit department articles', 'publish articles');
    });

// 役員は他部署記事閲覧可能
Role::where('name', 'like', '%-director')
    ->get()
    ->each(function ($role) {
        $role->givePermissionTo('view other department articles');
    });
```

**使用時のコード**
```php
// コントローラーでの権限チェック
public function edit(Article $article)
{
    if ($user->can('edit own articles') && $user->id === $article->user_id) {
        // 本人の記事編集
    } elseif ($user->can('edit department articles') && 
              $user->department === $article->author->department) {
        // 部署内記事編集
    } else {
        abort(403);
    }
}

public function index()
{
    $query = Article::query();
    
    if (!$user->can('view other department articles')) {
        $query->whereHas('author', function ($q) use ($user) {
            $q->where('department', $user->department);
        });
    }
    
    return $query->get();
}
```

### 実装比較による考察

**保守性の観点**
Gate/Policyでは権限変更時にコード修正とテストが必要になる。特に組織変更時の影響範囲が広い。Laravel Permissionでは管理画面から権限変更が可能で、開発者の介入なしに運用チームが対応できる。

**拡張性の観点**
新しい部署や役職の追加時、Gate/Policyでは各権限定義の見直しが必要である。Laravel Permissionでは新しいロールの作成と既存権限の組み合わせで対応できる。

### Laravel Permissionにおけるベストプラクティス
Laravel Permissionを使用する際は、RolesとPermissionsの適切な設計が重要である。公式ドキュメントでは以下のベストプラクティスが推奨されている。

**権限名の設計原則**
権限名は可能な限り詳細かつ具体的に命名する。`manage posts`ではなく`create posts`、`edit posts`、`delete posts`のように分割することで、細かな権限制御が可能になる。

**ロールベースの権限継承**
ユーザーに直接権限を付与せず、必ずロール経由で権限を継承させる。これにより権限管理の一貫性が保たれ、権限の追跡と変更が容易になる。

**権限チェックの実装方針**
アプリケーション内では、ロール名ではなく権限名で権限チェックを行う。これにより、組織変更時でも権限ロジックの修正が最小限に抑えられる。
[Roles vs Permissions](https://spatie.be/docs/laravel-permission/v6/best-practices/roles-vs-permissions)

### ハイブリッドアプローチの提案

実際の開発では、両アプローチの組み合わせが効果的であると考える。

```php
// Laravel Permissionでベース権限を管理
if (!$user->hasRole('manager|director')) {
    abort(403);
}

// Gateで複雑な条件を判定
Gate::define('edit-article-with-conditions', function ($user, $article) {
    // 基本権限はLaravel Permissionで確認済み
    // ここでは時間制限など複雑な条件のみ判定
    if ($article->status === 'published') {
        return $article->created_at->diffInHours() <= 24;
    }
    return true;
});
```

このアプローチにより、組織的な権限管理の効率性と、複雑なビジネスロジックへの対応力を両立できる。

## まとめ

Laravelにおける権限管理は、システムの要件と運用体制に応じて適切な手法を選択することが重要である。

**Gate/Policyが適している場面**
- 小規模なアプリケーション
- 複雑な条件判定が中心の権限管理
- 権限変更が稀で、開発チームが管理する場合

**Laravel Permissionが適している場面**
- 中規模以上のアプリケーション
- 組織構造に基づいた権限管理
- 運用チームが権限を動的に管理する必要がある場合

私の経験上、多くの企業システムではLaravel Permissionをベースとし、必要に応じてGateで補完する混合アプローチが最も実用的である。これにより、運用効率と開発柔軟性を両立できる。

また、権限管理は一度実装すれば完了ではなく、組織の変化や要件の進化に応じて継続的な見直しが必要である。

なお、この記事で紹介した内容以外にも、より良いプラクティスや異なる観点からの意見をお持ちの方がいらっしゃいましたら、ぜひお聞かせいただきたい。

## 参考記事
[Laravel Permission](https://spatie.be/docs/laravel-permission/v6/introduction)
[Laravel Authorization](https://laravel.com/docs/12.x/authorization)
