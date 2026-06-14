# Hooks

O Ingestor oferece um conjunto reduzido de hooks para integração com o ciclo de vida da importação.

Hooks permitem executar comportamentos auxiliares, como:

* auditoria;
* métricas;
* notificações;
* logs;
* integrações externas;
* validações finais antes da publicação.

Hooks não participam da transformação das linhas e não devem alterar o pipeline interno de ingestão.

---

# Filosofia

Hooks são eventos de alto nível.

Eles são executados poucas vezes durante uma importação e seu custo é constante, independentemente da quantidade de linhas processadas.

Por esse motivo, o Ingestor não possui hooks por linha ou por mutação.

Exemplo:

```text id="vw2ewm"
5 milhões de linhas
        ↓
4 hooks executados
```

e não:

```text id="sgb8yw"
5 milhões de linhas
        ↓
10 milhões de callbacks
```

O objetivo é manter o pipeline previsível e performático.

---

# Ciclo de Vida

```text id="avw0nl"
beforeImport()
        ↓
prepare()
        ↓
validate()
        ↓
map()
        ↓
persist()
        ↓
afterImport()
        ↓
release()
        ↓
beforeRelease()
        ↓
promote stage
        ↓
afterRelease()
```

---

# BeforeImport

Executado uma única vez antes do início da importação.

```php id="g1c8dm"
interface BeforeImport
{
    public function beforeImport(
        Context $context,
    ): void;
}
```

Casos de uso:

* iniciar cronômetros;
* adicionar informações ao Context;
* configurar logs;
* preparar recursos externos;
* registrar auditoria de início.

---

# AfterImport

Executado após o término da importação e antes de qualquer publicação.

```php id="b4pxwx"
interface AfterImport
{
    public function afterImport(
        ImportedImport $import,
    ): void;
}
```

Casos de uso:

* registrar métricas;
* analisar failures;
* enviar notificações;
* gerar relatórios;
* realizar verificações pós-importação.

Neste momento:

* todas as linhas já foram processadas;
* todas as failures já foram coletadas;
* o staging ainda não foi publicado.

---

# BeforeRelease

Executado imediatamente antes da promoção do staging.

```php id="lmkqhh"
interface BeforeRelease
{
    public function beforeRelease(
        ImportedImport $import,
    ): void;
}
```

Casos de uso:

* validações finais;
* aprovações manuais;
* auditoria;
* impedir publicações indevidas;
* integrações de pré-publicação.

O hook pode impedir a publicação lançando uma exceção.

Exemplo:

```php id="sgo3gq"
throw CannotRelease::because(
    'Import contains unresolved failures.'
);
```

---

# AfterRelease

Executado após a publicação do staging.

```php id="i8iqx0"
interface AfterRelease
{
    public function afterRelease(
        ReleasedImport $import,
    ): void;
}
```

Casos de uso:

* limpar caches;
* sincronizar sistemas externos;
* emitir eventos;
* enviar notificações;
* registrar auditoria de publicação.

Neste momento:

* o staging já foi promovido;
* os dados já estão publicados;
* a importação é considerada concluída.

---

# Garantias

Hooks possuem as seguintes garantias:

```text id="jmy6on"
beforeImport()  → executado uma única vez
afterImport()   → executado uma única vez
beforeRelease() → executado uma única vez
afterRelease()  → executado uma única vez
```

A execução dos hooks é independente:

* do número de linhas;
* do tamanho dos lotes;
* da estratégia de persistência;
* do driver de origem;
* do driver de banco de dados.

---

# Responsabilidades

Hooks existem para integrar o Ingestor com o mundo externo.

Eles não devem:

* validar linhas;
* transformar dados;
* produzir mutações;
* substituir `prepare()`;
* substituir `map()`;
* depender da quantidade de registros processados.

O pipeline de ingestão continua sendo:

```text id="rpxg1r"
Row + Context
        ↓
     Dataset
```

Enquanto os hooks atuam exclusivamente sobre o ciclo de vida da importação:

```text id="drk45k"
Importação
        ↓
Integrações
Auditoria
Logs
Métricas
Notificações
```

Essa separação mantém o Ingestor:

```text id="s4f3dg"
Simples
Determinístico
Performático
Auditável
Extensível
```
