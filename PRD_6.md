# Metrics

O Ingestor coleta métricas durante toda a execução da importação.

Métricas permitem:

* exibir um resumo da importação;
* gerar auditorias;
* registrar logs;
* monitorar performance;
* identificar gargalos;
* comparar estratégias de persistência;
* acompanhar falhas e throughput.

As métricas são acumuladas de forma incremental e permanecem disponíveis independentemente da importação ser publicada ou descartada.

---

# Filosofia

Métricas são somente leitura.

Seu objetivo é descrever o que aconteceu durante a importação.

Elas nunca influenciam:

* validação;
* transformação;
* persistência;
* publicação.

Conceitualmente:

```text id="y3egvt"
Importação
      ↓
Coleta de métricas
      ↓
Consulta
```

---

# Disponibilidade

As métricas estão disponíveis após a conclusão da importação.

```php id="my9rxt"
$import = $ingestor
    ->for(CustomerImport::class)
    ->from($file)
    ->import();

$metrics = $import->metrics();
```

Elas também permanecem disponíveis após a publicação.

```php id="r7a03j"
$released = $import->release();

$metrics = $released->metrics();
```

---

# Contrato

```php id="yej3om"
interface Metrics
{
    public function startedAt(): DateTimeInterface;

    public function finishedAt(): ?DateTimeInterface;

    public function duration(): Duration;

    public function rows(): int;

    public function importedRows(): int;

    public function failedRows(): int;

    public function mutations(): int;

    /**
     * @return iterable<DatasetMetrics>
     */
    public function datasets(): iterable;
}
```

---

# Métricas de Tempo

```php id="l1u1lb"
$metrics->startedAt();

$metrics->finishedAt();

$metrics->duration();
```

Permitem responder perguntas como:

```text id="htw79f"
Quando a importação começou?
Quando terminou?
Quanto tempo levou?
```

---

# Métricas de Linhas

```php id="0ntncv"
$metrics->rows();

$metrics->importedRows();

$metrics->failedRows();
```

Exemplo:

```text id="7r5l80"
Linhas processadas: 500.000
Linhas importadas: 499.812
Linhas com falha: 188
```

---

# Métricas de Transformação

Uma linha pode gerar nenhuma, uma ou várias mutações.

```php id="nlv65q"
$metrics->mutations();
```

Exemplo:

```text id="pkx5qq"
Linhas processadas: 500.000
Mutações produzidas: 842.195
```

---

# Métricas por Dataset

Importações podem produzir múltiplos datasets.

Por esse motivo, o Ingestor expõe métricas específicas para cada dataset.

```php id="p2wt9e"
interface DatasetMetrics
{
    public function name(): string;

    public function mutations(): int;

    public function persisted(): int;

    public function failures(): int;
}
```

---

# Exemplo

```php id="rn55fq"
foreach ($import->metrics()->datasets() as $dataset) {
    dump([
        'dataset' => $dataset->name(),
        'mutations' => $dataset->mutations(),
        'persisted' => $dataset->persisted(),
        'failures' => $dataset->failures(),
    ]);
}
```

Exemplo de saída:

```text id="vho71o"
customers
Mutations: 500.000
Persisted: 499.812
Failures: 188

addresses
Mutations: 500.000
Persisted: 500.000
Failures: 0

phones
Mutations: 342.195
Persisted: 342.195
Failures: 0
```

---

# Resumo

Exemplo:

```text id="h4sl66"
Importação
─────────────────────────
Linhas processadas: 500.000
Linhas importadas: 499.812
Linhas com falha: 188

Datasets produzidos: 3
Mutações produzidas: 842.195

Tempo total: 1m42s
```

---

# Relação com Failures

As métricas não substituem as failures.

Elas respondem perguntas diferentes.

Failures:

```text id="hq3kgq"
O que falhou?
Qual linha falhou?
Por que falhou?
```

Metrics:

```text id="g10m5c"
Quantas linhas foram processadas?
Quantas falharam?
Quanto tempo levou?
Quantas mutações foram produzidas?
```

Os dois conceitos são complementares.

---

# Relação com Release

As métricas representam o estado da importação no momento em que ela terminou.

Elas continuam disponíveis independentemente da decisão tomada posteriormente.

```text id="0wgrvs"
import()
      ↓
metrics()
      ↓
release()
ou
rollback()
```

Isso permite:

* apresentar relatórios antes da publicação;
* auditar importações descartadas;
* comparar execuções;
* monitorar performance do sistema.

---

# Filosofia

Métricas transformam a importação em uma operação observável.

O objetivo é que qualquer execução possa responder:

```text id="xw56ea"
O que aconteceu?
Quanto foi processado?
Quanto falhou?
Quanto foi produzido?
Quanto tempo levou?
```

independentemente:

* do driver de origem;
* do banco de dados;
* da estratégia de persistência;
* da decisão de publicar ou descartar o staging.

Uma importação no Ingestor deve ser:

```text id="2gs4e7"
Observável
Auditável
Diagnosticável
Mensurável
```
