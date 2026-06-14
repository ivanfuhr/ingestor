# Context

O Context representa um armazenamento compartilhado durante a execução de uma importação.

Seu objetivo é permitir que uma importação pré-carregue dependências e dados auxiliares que serão utilizados durante o processamento das linhas.

Exemplos:

* mapas de IDs;
* configurações;
* dados de referência;
* caches;
* respostas de APIs;
* qualquer estrutura necessária para enriquecer os dados importados.

O Context não conhece:

* banco de dados;
* Eloquent;
* CSV;
* regras de negócio.

Ele é apenas um armazenamento de dados associado à execução da importação.

---

# Motivação

É comum uma importação precisar resolver relacionamentos.

Exemplo:

```text
CSV
└── customer_document
        ↓
customers
└── id
```

Uma implementação ingênua poderia fazer:

```php
Customer::where('document', $row['document'])->value('id');
```

Com 500 mil linhas isso resultaria em:

```text
500 mil SELECTs
```

Tornando a importação extremamente lenta.

O Context permite que esses dados sejam carregados uma única vez:

```php
Customer::pluck('id', 'document')->all();
```

e reutilizados em memória durante toda a execução.

---

# Contrato

```php
interface Context
{
    public function put(string $key, mixed $value): void;

    public function get(string $key): mixed;

    public function has(string $key): bool;
}
```

---

# Preparando dados

Algumas importações podem optar por pré-carregar dados antes do início do processamento.

```php
interface Preparable
{
    public function prepare(Context $context): void;
}
```

Exemplo:

```php
final class CustomerImport implements
    Definition,
    Preparable
{
    public function prepare(Context $context): void
    {
        $context->put(
            'customers',
            Customer::pluck('id', 'document')->all()
        );

        $context->put(
            'cities',
            City::pluck('id', 'name')->all()
        );
    }
}
```

---

# Utilizando dados pré-carregados

O Context também é disponibilizado durante o mapeamento das linhas.

```php
interface Definition
{
    public function schema(): Schema;

    public function map(
        array $row,
        Context $context,
    ): Dataset;
}
```

Exemplo:

```php
public function map(
    array $row,
    Context $context,
): Dataset {
    $customers = $context->get('customers');
    $cities = $context->get('cities');

    $customerId = $customers[$row['document']] ?? null;
    $cityId = $cities[$row['city']] ?? null;

    return Dataset::make()
        ->insert('orders', [
            'customer_id' => $customerId,
            'city_id' => $cityId,
            'total' => $row['total'],
        ]);
}
```

---

# Filosofia

As Definitions devem ser preferencialmente puras.

O método `map()` deve idealmente ser:

```text
Row + Context
        ↓
     Dataset
```

Operações de I/O, consultas e carregamento de dependências devem acontecer antecipadamente através do `Context`, permitindo que o processamento de milhões de linhas seja realizado utilizando apenas estruturas em memória.

Essa abordagem reduz drasticamente o número de consultas e melhora significativamente o throughput da importação.
