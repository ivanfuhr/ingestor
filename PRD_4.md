# Diagnóstico de Falhas de Persistência

Nem todos os problemas encontrados durante uma importação são erros de validação.

É possível que uma mutação falhe durante a persistência por motivos como:

* restrições `NOT NULL`;
* violações de `FOREIGN KEY`;
* violações de `UNIQUE`;
* tipos incompatíveis;
* outras restrições impostas pelo banco de dados.

Exemplos:

```text
null value in column "document" violates not-null constraint

insert or update on table "orders"
violates foreign key constraint

duplicate key value violates unique constraint
```

Esses erros podem acontecer mesmo quando a linha foi considerada válida pela etapa de validação.

---

# Objetivo

O Ingestor deve:

1. identificar que ocorreu uma falha de persistência;
2. associar a falha à linha original;
3. disponibilizar as informações para o usuário;
4. permitir que o usuário decida entre `release()` ou `rollback()`.

O Ingestor nunca deve executar um `release()` automaticamente.

---

# Contexto de Linha

Cada linha processada recebe um contexto de origem.

```php
interface RowContext
{
    public function line(): int;

    public function data(): array;
}
```

Exemplo:

```text
Linha: 1523

Dados:
{
    "name": "João",
    "document": null
}
```

---

# Propagação de Origem

Toda mutação produzida pelo `map()` herda o contexto da linha que a originou.

Conceitualmente:

```text
CSV
    ↓
RowContext
    ↓
map()
    ↓
Mutation
    ↓
Persistência
```

Isso permite rastrear a origem de qualquer falha ocorrida durante a escrita.

---

# Failure

Erros de persistência são expostos através do mesmo mecanismo utilizado pelas validações.

```php
interface Failure
{
    public function line(): ?int;

    public function dataset(): ?string;

    public function data(): ?array;

    public function message(): string;

    public function severity(): Severity;

    public function cause(): ?Throwable;
}
```

Exemplo:

```text
Linha: 1523
Dataset: customers

Erro:
null value in column "document"
violates not-null constraint

Dados:
{
    "name": "João",
    "document": null
}
```

---

# Fluxo de Importação

```text
prepare()
        ↓
validate()
        ↓
map()
        ↓
persist()
        ↓
failures()
        ↓
release() ou rollback()
```

A presença de falhas não implica em um rollback automático.

A decisão pertence ao consumidor da biblioteca.

---

# Exemplo

```php
$import = $ingestor
    ->for(CustomerImport::class)
    ->from($file)
    ->import();

if ($import->hasFailures()) {
    foreach ($import->failures() as $failure) {
        dump([
            'line' => $failure->line(),
            'dataset' => $failure->dataset(),
            'message' => $failure->message(),
            'data' => $failure->data(),
        ]);
    }

    return;
}

$import->release();
```

ou:

```php
$import->rollback();
```

---

# Modos de Diagnóstico

Nem todos os mecanismos de escrita do banco de dados conseguem identificar imediatamente qual linha falhou.

Por esse motivo, o driver pode operar em diferentes modos de diagnóstico.

```php
enum SqlFailureMode
{
    case Fast;

    case Diagnostic;
}
```

---

# Fast

Prioriza throughput.

Quando um lote falha:

```text
500 linhas
      ↓
1 INSERT
      ↓
Falha
      ↓
Registrar falha do lote
```

O driver pode não conseguir determinar exatamente qual linha falhou.

---

# Diagnostic

Prioriza rastreabilidade.

Quando um lote falha:

```text
500 linhas
      ↓
1 INSERT
      ↓
Falha
      ↓
Subdividir lote
      ↓
Isolar linha problemática
```

Esse modo permite identificar precisamente:

* linha original;
* dataset;
* dados da linha;
* mensagem do banco de dados.

---

# Filosofia

O Ingestor é um mecanismo de staging.

Uma importação é considerada concluída apenas quando:

```php
$import->release();
```

Até esse momento:

* staging continua isolado;
* falhas continuam disponíveis;
* o consumidor pode inspecionar os problemas encontrados;
* o consumidor decide se os dados devem ser promovidos ou descartados.

O objetivo é que uma importação seja:

```text
Segura
Auditável
Diagnosticável
Controlada pelo consumidor
```

mesmo quando milhões de linhas estão sendo processadas.
