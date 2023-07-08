---
title: "PHP 8.0の新機能「アトリビュート」を初体験"
emoji: "✨"
type: "tech"
topics: ["php"]
published: false
---
## はじめに
PHP 8.0から追加された機能として、「アトリビュート」があります。
私はアトリビュートに関して、これまでほとんど触れる機会がなかったため、PHPマニュアルを参考にしながら、アトリビュートを体験することを目的とした記事になります。
この記事内で誤った表現や理解を見つけた場合は、ぜひご指摘ください。

## アトリビュートとは
PHPマニュアルでは、「アトリビュート」について以下のように説明されています。

> アトリビュートを使うと、 コンピューターが解析できる構造化されたメタデータの情報を、 コードの宣言時に埋め込むことができます。 つまり、クラス、メソッド、関数、パラメータ、プロパティ、クラス定数にアトリビュートを指定することができます。 アトリビュートで定義されたメタデータは、 実行時に リフレクションAPI を使って調べることが出来ます。 よって、アトリビュートは、 コードに直接埋め込むことが出来る、 設定のための言語とみなすことができます。
>
> 参照: [PHP Manual](https://www.php.net/manual/ja/language.attributes.overview.php)

さらに、マニュアルには次のような記述もあります。

> アトリビュートを使うと、機能の抽象的な実装と、アプリケーションでの具体的な利用を分離できます。 この点でアトリビュートは、インターフェイスとその実装と比較できます。 インターフェイスとその実装はコードに関する情報ですが、 アトリビュートはコードの追加情報と設定に注釈を付けるものです。 インターフェイスはクラスによって実装できますが、 アトリビュートはメソッドや関数、パラメータ、プロパティ、クラス定数で宣言できます。 よって、アトリビュートはインターフェイスより柔軟です。
>
> 参照: [PHP Manual](https://www.php.net/manual/ja/language.attributes.overview.php)

私自身、これらの説明だけでは、「アトリビュート」が抽象的な実装と、アプリケーションでの具体的な利用をどのように分離できるのか、十分理解できなかったため、この記事ではその部分に焦点を当てています。
## アトリビュートの実装例
話題に入る前にアトリビュートの動作について、サンプルコードで確認していきましょう。

アトリビュートクラスの宣言は次のように行います。アトリビュートを適用可能な場所を制限するためには、第一引数にビットマスクを渡すことができます。今回は、どの場所でも使用可能にするためにAttribute::TARGET_ALLを指定しています。

````php
<?php

#[Attribute(Attribute::TARGET_ALL)]
class DebugInfoAttribute {
    public function __construct(public string $message)
    {
    }
}
````

先ほど宣言したアトリビュートクラスを次のようにクラス、プロパティ、メソッド、引数に適用します。

````php
#[DebugInfoAttribute("This is a sample class")]
class Sample {
    #[DebugInfoAttribute('This is a sample property')]
    public $sampleProperty;

    #[DebugInfoAttribute('This is a sample method')]
    public function sampleMethod(
        #[DebugInfoAttribute('This is a sample argument')] $sampleArgument
    ) {
    }
}
````

次に、アトリビュートの情報を取得するための関数を定義します。
アトリビュートにアクセスするためには、リフレクションオブジェクトを利用します。
リフレクションオブジェクトには、`getAttributes()`メソッドが提供されているため、それらを利用して取得することが可能です。

````php
function debug_attributes($object) {
    $reflection = new ReflectionClass($object);

    // クラス名を取得
    echo $reflection->getName(), "\n";
    
    // クラスの属性を取得
    foreach ($reflection->getAttributes() as $attribute) {
        echo '  ', $attribute->getName(), ': ', $attribute->newInstance()->message, "\n";
    }

    // プロパティの属性を取得
    foreach ($reflection->getProperties() as $property) {
        echo '  ', $property->getName(), "\n";
        foreach ($property->getAttributes() as $attribute) {
            echo '    ', $attribute->getName(), ': ', $attribute->newInstance()->message, "\n";
        }
    }

    // メソッドの属性を取得
    foreach ($reflection->getMethods() as $method) {
        echo '  ', $method->getName(), "\n";
        foreach ($method->getAttributes() as $attribute) {
            echo '    ', $attribute->getName(), ': ', $attribute->newInstance()->message, "\n";
        }
        // メソッドの引数の属性を取得
        foreach ($method->getParameters() as $parameter) {
            foreach ($parameter->getAttributes() as $attribute) {
                echo '      ', $attribute->getName(), ': ', $attribute->newInstance()->message, "\n";
            }
        }
    }
}
````

先ほどの関数を使って、次のようにアトリビュートの情報を表示します。

````php
debug_attributes(new Sample());
// Sample
//   DebugInfoAttribute: This is a sample class
//   sampleProperty
//     DebugInfoAttribute: This is a sample property
//   sampleMethod
//     DebugInfoAttribute: This is a sample method
//       DebugInfoAttribute: This is a sample argument
````

以上がアトリビュートの基本的な動作確認になります。ここまでで、「メタデータの情報をコードの宣言時に埋め込む」という説明が実際のコードと合わせて理解できたのではないでしょうか。
## アトリビュートによる抽象化と具体化

初めに、アトリビュートが抽象的な実装と具体的な利用を分離できると述べました。これは、アトリビュートがメタデータの役割だけを果たし、そのメタデータの利用方法はアプリケーションのコードに依存します。つまり、メタデータの定義（抽象化）とその活用（具体化）が分離されるのです。

具体的な例を挙げて説明します。ここでは、アトリビュートを用いた文字列のバリデーションを行うコードを見ていきましょう。なお、バリデーションをアトリビュートで実装することが適切かどうかはこの記事では範囲外とします...。

````php
<?php

#[Attribute]
class ValidateStringLength {
    public function __construct(
        public int $min,
        public int $max
    ) {}
}

class User {
    #[ValidateStringLength(1, 10)]
    public string $name;

    public function __construct(string $name) {
        $this->validate($name);
        $this->name = $name;
    }

    private function validate(string $name): void {
        $refl = new ReflectionProperty(User::class, 'name');
        $attributes = $refl->getAttributes(ValidateStringLength::class);
        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            if (strlen($name) < $instance->min || strlen($name) > $instance->max) {
                throw new InvalidArgumentException('Invalid string length');
            }
        }
    }
}

$user = new User('John');
````

ここでは、ValidateStringLengthクラスがバリデーションに関連する特定のパラメーター（文字列の長さ）の抽象表現を担当しています。ValidateStringLengthクラスは「文字列の長さを検証する」という概念を表現していますが、具体的な最小値や最大値は指定せず、それらはアトリビュートが具体的に適用される際に提供されます。

一方で、UserクラスはValidateStringLengthアトリビュートを具体的に利用しています。Userクラスのnameプロパティに対してValidateStringLengthアトリビュートを適用し、具体的な最小値（1）と最大値（10）を設定しています。この具体的な値を用いて、「ユーザー名の検証」という具体的な操作を実現してます。

この例から、アトリビュートが「何をするか」（抽象的なバリデーションルール）と「それをどう適用するか」（具体的なバリデーションパラメータ）を分離する役割を果たすことができることを理解できました。

## まとめ
PHP 8.0からの新機能である「アトリビュート」を初めて体験してみました。アトリビュートはメタデータをコードに関連付けることができる機能であり、これによって抽象的な実装と具体的な利用の分離が可能となることが分かりました。

しかし、私のPHPの理解がまだまだ浅いため、アトリビュートがどのようなシーンで活用できるのか具体的にはまだ理解できていません。
今後もPHPの理解を深めつつ、アトリビュートに対応したフレームワークやライブラリがどのように発展していくのかにも注目していきたいです。

## 参考URL
- [PHP: アトリビュート - Manual](https://www.php.net/manual/ja/language.attributes.php)
