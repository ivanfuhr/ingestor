# Validação de Linhas

Nem toda linha recebida pela importação é necessariamente válida.

É comum que uma importação precise:

* verificar campos obrigatórios;
* validar formatos;
* validar relacionamentos;
* garantir consistência de dados;
* emitir avisos para dados incompletos.

A validação acontece antes do mapeamento e antes que qualquer mutação seja produzida.

Fluxo:

```text
prepare(Context)
        ↓
validate(Row, Context)
        ↓
map(Row, Context)
        ↓
Dataset
```

---

# Contrato

As validações são opcionais.

```php
interface ValidatesRows
{
    public function validate(
        array $row,
        Context $context,
    ): iterable;
}
```

A implementação deve retornar uma coleção de falhas encontradas na linha.

Retornar um iterável permite:

* nenhum erro;
* um erro;
* vários erros;
* erros e avisos simultaneamente.

---

# Failure

Uma falha representa um problema encontrado durante a validação.

```php
interface Failure
{
    public function field(): ?string;

    public function message(): string;

    public function severity(): Severity;
}
```

---

# Severity

Uma falha pode possuir diferentes níveis de severidade.

```php
enum Severity
{
    case ERROR;

    case WARNING;
}
```

Exemplos:

```text
ERROR
Document is required.

WARNING
Phone number is empty.
```

---

# Exemplo

```php
final class CustomerImport implements
    Definition,
    Preparable,
    ValidatesRows
{
    public function validate(
        array $row,
        Context $context,
    ): iterable {
        if (empty($row['document'])) {
            yield Failure::error('document')
                ->message('Document is required.');
        }

        if (empty($row['phone'])) {
            yield Failure::warning('phone')
                ->message('Phone number is empty.');
        }
    }
}
```

---

# Utilizando o Context

Validações frequentemente dependem de dados previamente carregados.

Exemplo:

```php
public function validate(
    array $row,
    Context $context,
): iterable {
    $cities = $context->get('cities');

    if (! isset($cities[$row['city']])) {
        yield Failure::error('city')
            ->message('City not found.');
    }
}
```

Isso evita consultas repetidas ao banco de dados durante a importação.

---

# Comportamento da Importação

Falhas com severidade `ERROR` tornam a linha inválida e impedem sua transformação em mutações.

Falhas com severidade `WARNING` não impedem o processamento da linha.

Conceitualmente:

```text
Linha
    ↓
Validação
    ↓
ERROR?
    ├── Sim → registrar falha e ignorar linha
    └── Não
            ↓
          map()
            ↓
         Dataset
```

---

# Registro de Falhas

As falhas encontradas durante a importação ficam disponíveis após a execução.

```php
$import = $ingestor
    ->for(CustomerImport::class)
    ->from($file)
    ->import();

$import->errors();
```

Isso permite:

* gerar relatórios de importação;
* exportar linhas inválidas;
* exibir mensagens para o usuário;
* realizar auditorias;
* permitir correções e reprocessamentos.

---

# Filosofia

Validações devem ser preferencialmente puras.

Uma validação ideal:

```text
Row + Context
        ↓
    Failures
```

Ela não deve:

* escrever no banco;
* criar mutações;
* executar efeitos colaterais.

Sua única responsabilidade é informar se uma linha pode ou não continuar no pipeline de ingestão.
