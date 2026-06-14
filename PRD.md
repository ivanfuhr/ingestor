# Arquitetura Geral

O Ingestor é composto por dois tipos de drivers:

1. **Source Driver**

   * Responsável por ler uma fonte de dados.
   * Exemplos: CSV, XLSX, JSON, XML.

2. **Persistence Driver**

   * Responsável por criar o ambiente de staging, persistir dados e publicá-los.
   * Exemplos: PostgreSQL, MySQL, SQLite.

---

# Construção

O Ingestor recebe suas dependências no momento da construção.

```php
$ingestor = new Ingestor(
    persistence: new PostgresDriver($pdo),
    source: new CsvDriver(),
);
```

A intenção é que a instância do Ingestor já conheça:

* como ler dados;
* como persistir dados.

A importação em si não precisa se preocupar com essas decisões.

---

# Fluxo de Uso

```php
$ingestor
    ->for(CustomerImport::class)
    ->from($file)
    ->import()
    ->release();
```

A leitura desse fluxo é:

1. Escolha uma definição de importação;
2. Informe uma fonte;
3. Execute a ingestão;
4. Publique os dados.

---

# Modelo Mental

```text
Source
    ↓
Source Driver
    ↓
Iterable<Row>
    ↓
Definition
    ↓
Dataset
    ↓
Persistence Driver
    ↓
Stage
    ↓
Release
```

---

# Responsabilidades

## Source Driver

Transforma uma fonte em linhas de entrada.

Exemplos:

```text
CSV      → Iterable<Row>
XLSX     → Iterable<Row>
JSON     → Iterable<Row>
```

Contrato:

```php
interface SourceDriver
{
    public function read(mixed $source): iterable;
}
```

O Source Driver não conhece:

* banco de dados;
* staging;
* release;
* regras de negócio.

Ele apenas produz linhas.

---

## Persistence Driver

Transforma conceitos do Ingestor em operações de persistência.

Exemplos:

```text
Dataset
    ↓
PostgreSQL

Dataset
    ↓
MySQL

Dataset
    ↓
SQLite
```

Contrato:

```php
interface PersistenceDriver
{
    public function begin(Definition $definition): Stage;

    public function ingest(Stage $stage, iterable $rows): void;

    public function release(Stage $stage): void;

    public function rollback(Stage $stage): void;
}
```

O Persistence Driver conhece:

* Definitions;
* Schemas;
* Datasets;
* Stages.

Ele não conhece:

* CSV;
* XLSX;
* Frameworks;
* regras de negócio.

---

# Definition

A Definition descreve uma importação.

Ela possui duas responsabilidades:

1. Descrever a estrutura da importação;
2. Transformar linhas de entrada em mutações.

```php
interface Definition
{
    public function schema(): Schema;

    public function map(array $row): Dataset;
}
```

---

# Schema

O Schema descreve a estrutura da ingestão.

Ele responde:

* Quais datasets existem?
* Como cada dataset deve nascer?
* Como conflitos devem ser tratados?

Exemplo:

```php
Schema::make()
    ->dataset('customers')
        ->using(PrefilledStage::class)
        ->onConflict(
            UpdateOnConflict::by('document')
        );

Schema::make()
    ->dataset('addresses')
        ->using(EmptyStage::class);
```

O Schema é descoberto apenas uma vez, antes do início da importação.

---

# Dataset

Representa as mutações produzidas por uma linha.

Exemplo:

```php
return Dataset::make()
    ->insert('customers', [
        'document' => $row['cpf'],
        'name' => $row['name'],
    ])
    ->insert('addresses', [
        'document' => $row['cpf'],
        'city' => $row['city'],
    ]);
```

Uma linha pode produzir:

* nenhum registro;
* um registro;
* vários registros;
* registros em vários datasets.

O Dataset apenas descreve intenções de escrita.

---

# Stage

Representa um ambiente isolado de ingestão.

Conceitualmente:

```text
Import
└── Stage
    ├── customers
    └── addresses
```

Nenhuma alteração é aplicada diretamente na origem até que um release seja executado.

---

# Estratégias de Stage

## EmptyStage

O dataset inicia vazio.

```php
Schema::make()
    ->dataset('customers')
        ->using(EmptyStage::class);
```

---

## PrefilledStage

O dataset nasce com uma cópia dos dados existentes.

```php
Schema::make()
    ->dataset('customers')
        ->using(PrefilledStage::class);
```

Esse modo é especialmente útil para:

* atualizações incrementais;
* importações parciais;
* enriquecimento de dados.

---

# Estratégias de Conflito

A resolução de conflitos pertence ao Schema.

Exemplos:

```php
UpdateOnConflict::by('document');

IgnoreOnConflict::by('document');

ReplaceOnConflict::by('document');

FailOnConflict::by('document');
```

Essas estratégias são declarativas.

A responsabilidade de traduzi-las para a tecnologia utilizada pertence ao Persistence Driver.

---

# Filosofia

O Ingestor é construído sobre a separação de quatro responsabilidades:

```text
Source Driver
    ↓
produz linhas

Definition
    ↓
produz mutações

Persistence Driver
    ↓
persiste mutações

Stage
    ↓
isola alterações

Release
    ↓
publica alterações
```

A ideia central é simples:

**dados entram por um driver de leitura, são transformados em mutações por uma definição, aplicados em um ambiente isolado por um driver de persistência e somente então são promovidos para produção de forma segura e atômica.**
