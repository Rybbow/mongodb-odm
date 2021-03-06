<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Mapping\Driver;

use Doctrine\Common\Persistence\Mapping\Driver\FileDriver;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Mapping\MappingException;
use Doctrine\ODM\MongoDB\Utility\CollectionHelper;
use DOMDocument;
use InvalidArgumentException;
use LibXMLError;
use SimpleXMLElement;
use function array_keys;
use function array_map;
use function assert;
use function constant;
use function count;
use function current;
use function explode;
use function implode;
use function in_array;
use function is_numeric;
use function iterator_to_array;
use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use function next;
use function preg_match;
use function simplexml_load_file;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * XmlDriver is a metadata driver that enables mapping through XML files.
 *
 * @method SimpleXMLElement getElement(string $className)
 */
class XmlDriver extends FileDriver
{
    public const DEFAULT_FILE_EXTENSION = '.dcm.xml';

    private const DEFAULT_GRIDFS_MAPPINGS = [
        'length' => [
            'name' => 'length',
            'type' => 'int',
            'notSaved' => true,
        ],
        'chunk-size' => [
            'name' => 'chunkSize',
            'type' => 'int',
            'notSaved' => true,
        ],
        'filename' => [
            'name' => 'filename',
            'type' => 'string',
            'notSaved' => true,
        ],
        'upload-date' => [
            'name' => 'uploadDate',
            'type' => 'date',
            'notSaved' => true,
        ],
    ];

    /**
     * {@inheritDoc}
     */
    public function __construct($locator, $fileExtension = self::DEFAULT_FILE_EXTENSION)
    {
        parent::__construct($locator, $fileExtension);
    }

    /**
     * {@inheritDoc}
     */
    public function loadMetadataForClass($className, \Doctrine\Common\Persistence\Mapping\ClassMetadata $class)
    {
        assert($class instanceof ClassMetadata);
        $xmlRoot = $this->getElement($className);

        if ($xmlRoot->getName() === 'document') {
            if (isset($xmlRoot['repository-class'])) {
                $class->setCustomRepositoryClass((string) $xmlRoot['repository-class']);
            }
        } elseif ($xmlRoot->getName() === 'mapped-superclass') {
            $class->setCustomRepositoryClass(
                isset($xmlRoot['repository-class']) ? (string) $xmlRoot['repository-class'] : null
            );
            $class->isMappedSuperclass = true;
        } elseif ($xmlRoot->getName() === 'embedded-document') {
            $class->isEmbeddedDocument = true;
        } elseif ($xmlRoot->getName() === 'query-result-document') {
            $class->isQueryResultDocument = true;
        } elseif ($xmlRoot->getName() === 'gridfs-file') {
            $class->isFile = true;

            if (isset($xmlRoot['chunk-size-bytes'])) {
                $class->setChunkSizeBytes((int) $xmlRoot['chunk-size-bytes']);
            }
        }

        if (isset($xmlRoot['db'])) {
            $class->setDatabase((string) $xmlRoot['db']);
        }

        if (isset($xmlRoot['collection'])) {
            if (isset($xmlRoot['capped-collection'])) {
                $config           = ['name' => (string) $xmlRoot['collection']];
                $config['capped'] = (bool) $xmlRoot['capped-collection'];
                if (isset($xmlRoot['capped-collection-max'])) {
                    $config['max'] = (int) $xmlRoot['capped-collection-max'];
                }
                if (isset($xmlRoot['capped-collection-size'])) {
                    $config['size'] = (int) $xmlRoot['capped-collection-size'];
                }
                $class->setCollection($config);
            } else {
                $class->setCollection((string) $xmlRoot['collection']);
            }
        }
        if (isset($xmlRoot['bucket-name'])) {
            $class->setBucketName((string) $xmlRoot['bucket-name']);
        }
        if (isset($xmlRoot['write-concern'])) {
            $class->setWriteConcern((string) $xmlRoot['write-concern']);
        }
        if (isset($xmlRoot['inheritance-type'])) {
            $inheritanceType = (string) $xmlRoot['inheritance-type'];
            $class->setInheritanceType(constant(ClassMetadata::class . '::INHERITANCE_TYPE_' . $inheritanceType));
        }
        if (isset($xmlRoot['change-tracking-policy'])) {
            $class->setChangeTrackingPolicy(constant(ClassMetadata::class . '::CHANGETRACKING_' . strtoupper((string) $xmlRoot['change-tracking-policy'])));
        }
        if (isset($xmlRoot->{'discriminator-field'})) {
            $discrField = $xmlRoot->{'discriminator-field'};
            $class->setDiscriminatorField((string) $discrField['name']);
        }
        if (isset($xmlRoot->{'discriminator-map'})) {
            $map = [];
            foreach ($xmlRoot->{'discriminator-map'}->{'discriminator-mapping'} as $discrMapElement) {
                $map[(string) $discrMapElement['value']] = (string) $discrMapElement['class'];
            }
            $class->setDiscriminatorMap($map);
        }
        if (isset($xmlRoot->{'default-discriminator-value'})) {
            $class->setDefaultDiscriminatorValue((string) $xmlRoot->{'default-discriminator-value'}['value']);
        }
        if (isset($xmlRoot->{'indexes'})) {
            foreach ($xmlRoot->{'indexes'}->{'index'} as $index) {
                $this->addIndex($class, $index);
            }
        }
        if (isset($xmlRoot->{'shard-key'})) {
            $this->setShardKey($class, $xmlRoot->{'shard-key'}[0]);
        }
        if (isset($xmlRoot['read-only']) && (string) $xmlRoot['read-only'] === 'true') {
            $class->markReadOnly();
        }
        if (isset($xmlRoot->{'read-preference'})) {
            $class->setReadPreference(...$this->transformReadPreference($xmlRoot->{'read-preference'}));
        }

        if (isset($xmlRoot->id)) {
            $field   = $xmlRoot->id;
            $mapping = [
                'id' => true,
                'fieldName' => 'id',
            ];

            /** @var SimpleXMLElement $attributes */
            $attributes = $field->attributes();
            foreach ($attributes as $key => $value) {
                $mapping[$key] = (string) $value;
            }

            if (isset($mapping['strategy'])) {
                $mapping['options'] = [];
                if (isset($field->{'generator-option'})) {
                    foreach ($field->{'generator-option'} as $generatorOptions) {
                        $attributesGenerator = iterator_to_array($generatorOptions->attributes());
                        if (! isset($attributesGenerator['name']) || ! isset($attributesGenerator['value'])) {
                            continue;
                        }

                        $mapping['options'][(string) $attributesGenerator['name']] = (string) $attributesGenerator['value'];
                    }
                }
            }

            $this->addFieldMapping($class, $mapping);
        }

        if (isset($xmlRoot->field)) {
            foreach ($xmlRoot->field as $field) {
                $mapping    = [];
                $attributes = $field->attributes();
                foreach ($attributes as $key => $value) {
                    $mapping[$key]     = (string) $value;
                    $booleanAttributes = ['reference', 'embed', 'unique', 'sparse'];
                    if (! in_array($key, $booleanAttributes)) {
                        continue;
                    }

                    $mapping[$key] = ($mapping[$key] === 'true');
                }

                if (isset($attributes['not-saved'])) {
                    $mapping['notSaved'] = ((string) $attributes['not-saved'] === 'true');
                }

                if (isset($attributes['field-name'])) {
                    $mapping['fieldName'] = (string) $attributes['field-name'];
                }

                if (isset($attributes['also-load'])) {
                    $mapping['alsoLoadFields'] = explode(',', $attributes['also-load']);
                } elseif (isset($attributes['version'])) {
                    $mapping['version'] = ((string) $attributes['version'] === 'true');
                } elseif (isset($attributes['lock'])) {
                    $mapping['lock'] = ((string) $attributes['lock'] === 'true');
                }

                $this->addFieldMapping($class, $mapping);
            }
        }

        $this->addGridFSMappings($class, $xmlRoot);

        if (isset($xmlRoot->{'embed-one'})) {
            foreach ($xmlRoot->{'embed-one'} as $embed) {
                $this->addEmbedMapping($class, $embed, 'one');
            }
        }
        if (isset($xmlRoot->{'embed-many'})) {
            foreach ($xmlRoot->{'embed-many'} as $embed) {
                $this->addEmbedMapping($class, $embed, 'many');
            }
        }
        if (isset($xmlRoot->{'reference-many'})) {
            foreach ($xmlRoot->{'reference-many'} as $reference) {
                $this->addReferenceMapping($class, $reference, 'many');
            }
        }
        if (isset($xmlRoot->{'reference-one'})) {
            foreach ($xmlRoot->{'reference-one'} as $reference) {
                $this->addReferenceMapping($class, $reference, 'one');
            }
        }
        if (isset($xmlRoot->{'lifecycle-callbacks'})) {
            foreach ($xmlRoot->{'lifecycle-callbacks'}->{'lifecycle-callback'} as $lifecycleCallback) {
                $class->addLifecycleCallback((string) $lifecycleCallback['method'], constant('Doctrine\ODM\MongoDB\Events::' . (string) $lifecycleCallback['type']));
            }
        }
        if (! isset($xmlRoot->{'also-load-methods'})) {
            return;
        }

        foreach ($xmlRoot->{'also-load-methods'}->{'also-load-method'} as $alsoLoadMethod) {
            $class->registerAlsoLoadMethod((string) $alsoLoadMethod['method'], (string) $alsoLoadMethod['field']);
        }
    }

    private function addFieldMapping(ClassMetadata $class, array $mapping) : void
    {
        if (isset($mapping['name'])) {
            $name = $mapping['name'];
        } elseif (isset($mapping['fieldName'])) {
            $name = $mapping['fieldName'];
        } else {
            throw new InvalidArgumentException('Cannot infer a MongoDB name from the mapping');
        }

        $class->mapField($mapping);

        // Index this field if either "index", "unique", or "sparse" are set
        if (! (isset($mapping['index']) || isset($mapping['unique']) || isset($mapping['sparse']))) {
            return;
        }

        $keys    = [$name => $mapping['order'] ?? 'asc'];
        $options = [];

        if (isset($mapping['background'])) {
            $options['background'] = (bool) $mapping['background'];
        }
        if (isset($mapping['index-name'])) {
            $options['name'] = (string) $mapping['index-name'];
        }
        if (isset($mapping['sparse'])) {
            $options['sparse'] = (bool) $mapping['sparse'];
        }
        if (isset($mapping['unique'])) {
            $options['unique'] = (bool) $mapping['unique'];
        }

        $class->addIndex($keys, $options);
    }

    private function addEmbedMapping(ClassMetadata $class, SimpleXMLElement $embed, string $type) : void
    {
        $attributes      = $embed->attributes();
        $defaultStrategy = $type === 'one' ? ClassMetadata::STORAGE_STRATEGY_SET : CollectionHelper::DEFAULT_STRATEGY;
        $mapping         = [
            'type'            => $type,
            'embedded'        => true,
            'targetDocument'  => isset($attributes['target-document']) ? (string) $attributes['target-document'] : null,
            'collectionClass' => isset($attributes['collection-class']) ? (string) $attributes['collection-class'] : null,
            'name'            => (string) $attributes['field'],
            'strategy'        => (string) ($attributes['strategy'] ?? $defaultStrategy),
        ];
        if (isset($attributes['field-name'])) {
            $mapping['fieldName'] = (string) $attributes['field-name'];
        }
        if (isset($embed->{'discriminator-field'})) {
            $attr                          = $embed->{'discriminator-field'};
            $mapping['discriminatorField'] = (string) $attr['name'];
        }
        if (isset($embed->{'discriminator-map'})) {
            foreach ($embed->{'discriminator-map'}->{'discriminator-mapping'} as $discriminatorMapping) {
                $attr                                                 = $discriminatorMapping->attributes();
                $mapping['discriminatorMap'][(string) $attr['value']] = (string) $attr['class'];
            }
        }
        if (isset($embed->{'default-discriminator-value'})) {
            $mapping['defaultDiscriminatorValue'] = (string) $embed->{'default-discriminator-value'}['value'];
        }
        if (isset($attributes['not-saved'])) {
            $mapping['notSaved'] = ((string) $attributes['not-saved'] === 'true');
        }
        if (isset($attributes['also-load'])) {
            $mapping['alsoLoadFields'] = explode(',', $attributes['also-load']);
        }
        $this->addFieldMapping($class, $mapping);
    }

    private function addReferenceMapping(ClassMetadata $class, $reference, string $type) : void
    {
        $cascade = array_keys((array) $reference->cascade);
        if (count($cascade) === 1) {
            $cascade = current($cascade) ?: next($cascade);
        }
        $attributes      = $reference->attributes();
        $defaultStrategy = $type === 'one' ? ClassMetadata::STORAGE_STRATEGY_SET : CollectionHelper::DEFAULT_STRATEGY;
        $mapping         = [
            'cascade'          => $cascade,
            'orphanRemoval'    => isset($attributes['orphan-removal']) ? ((string) $attributes['orphan-removal'] === 'true') : false,
            'type'             => $type,
            'reference'        => true,
            'storeAs'          => (string) ($attributes['store-as'] ?? ClassMetadata::REFERENCE_STORE_AS_DB_REF),
            'targetDocument'   => isset($attributes['target-document']) ? (string) $attributes['target-document'] : null,
            'collectionClass'  => isset($attributes['collection-class']) ? (string) $attributes['collection-class'] : null,
            'name'             => (string) $attributes['field'],
            'strategy'         => (string) ($attributes['strategy'] ?? $defaultStrategy),
            'inversedBy'       => isset($attributes['inversed-by']) ? (string) $attributes['inversed-by'] : null,
            'mappedBy'         => isset($attributes['mapped-by']) ? (string) $attributes['mapped-by'] : null,
            'repositoryMethod' => isset($attributes['repository-method']) ? (string) $attributes['repository-method'] : null,
            'limit'            => isset($attributes['limit']) ? (int) $attributes['limit'] : null,
            'skip'             => isset($attributes['skip']) ? (int) $attributes['skip'] : null,
            'prime'            => [],
        ];

        if (isset($attributes['field-name'])) {
            $mapping['fieldName'] = (string) $attributes['field-name'];
        }
        if (isset($reference->{'discriminator-field'})) {
            $attr                          = $reference->{'discriminator-field'};
            $mapping['discriminatorField'] = (string) $attr['name'];
        }
        if (isset($reference->{'discriminator-map'})) {
            foreach ($reference->{'discriminator-map'}->{'discriminator-mapping'} as $discriminatorMapping) {
                $attr                                                 = $discriminatorMapping->attributes();
                $mapping['discriminatorMap'][(string) $attr['value']] = (string) $attr['class'];
            }
        }
        if (isset($reference->{'default-discriminator-value'})) {
            $mapping['defaultDiscriminatorValue'] = (string) $reference->{'default-discriminator-value'}['value'];
        }
        if (isset($reference->{'sort'})) {
            foreach ($reference->{'sort'}->{'sort'} as $sort) {
                $attr                                     = $sort->attributes();
                $mapping['sort'][(string) $attr['field']] = (string) ($attr['order'] ?? 'asc');
            }
        }
        if (isset($reference->{'criteria'})) {
            foreach ($reference->{'criteria'}->{'criteria'} as $criteria) {
                $attr                                         = $criteria->attributes();
                $mapping['criteria'][(string) $attr['field']] = (string) $attr['value'];
            }
        }
        if (isset($attributes['not-saved'])) {
            $mapping['notSaved'] = ((string) $attributes['not-saved'] === 'true');
        }
        if (isset($attributes['also-load'])) {
            $mapping['alsoLoadFields'] = explode(',', $attributes['also-load']);
        }
        if (isset($reference->{'prime'})) {
            foreach ($reference->{'prime'}->{'field'} as $field) {
                $attr               = $field->attributes();
                $mapping['prime'][] = (string) $attr['name'];
            }
        }

        $this->addFieldMapping($class, $mapping);
    }

    private function addIndex(ClassMetadata $class, SimpleXMLElement $xmlIndex) : void
    {
        $attributes = $xmlIndex->attributes();

        $keys = [];

        foreach ($xmlIndex->{'key'} as $key) {
            $keys[(string) $key['name']] = (string) ($key['order'] ?? 'asc');
        }

        $options = [];

        if (isset($attributes['background'])) {
            $options['background'] = ((string) $attributes['background'] === 'true');
        }
        if (isset($attributes['name'])) {
            $options['name'] = (string) $attributes['name'];
        }
        if (isset($attributes['sparse'])) {
            $options['sparse'] = ((string) $attributes['sparse'] === 'true');
        }
        if (isset($attributes['unique'])) {
            $options['unique'] = ((string) $attributes['unique'] === 'true');
        }

        if (isset($xmlIndex->{'option'})) {
            foreach ($xmlIndex->{'option'} as $option) {
                $value = (string) $option['value'];
                if ($value === 'true') {
                    $value = true;
                } elseif ($value === 'false') {
                    $value = false;
                } elseif (is_numeric($value)) {
                    $value = preg_match('/^[-]?\d+$/', $value) ? (int) $value : (float) $value;
                }
                $options[(string) $option['name']] = $value;
            }
        }

        if (isset($xmlIndex->{'partial-filter-expression'})) {
            $partialFilterExpressionMapping = $xmlIndex->{'partial-filter-expression'};

            if (isset($partialFilterExpressionMapping->and)) {
                foreach ($partialFilterExpressionMapping->and as $and) {
                    if (! isset($and->field)) {
                        continue;
                    }

                    $partialFilterExpression = $this->getPartialFilterExpression($and->field);
                    if (! $partialFilterExpression) {
                        continue;
                    }

                    $options['partialFilterExpression']['$and'][] = $partialFilterExpression;
                }
            } elseif (isset($partialFilterExpressionMapping->field)) {
                $partialFilterExpression = $this->getPartialFilterExpression($partialFilterExpressionMapping->field);

                if ($partialFilterExpression) {
                    $options['partialFilterExpression'] = $partialFilterExpression;
                }
            }
        }

        $class->addIndex($keys, $options);
    }

    private function getPartialFilterExpression(SimpleXMLElement $fields) : array
    {
        $partialFilterExpression = [];
        foreach ($fields as $field) {
            $operator = (string) $field['operator'] ?: null;

            if (! isset($field['value'])) {
                if (! isset($field->field)) {
                    continue;
                }

                $nestedExpression = $this->getPartialFilterExpression($field->field);
                if (! $nestedExpression) {
                    continue;
                }

                $value = $nestedExpression;
            } else {
                $value = trim((string) $field['value']);
            }

            if ($value === 'true') {
                $value = true;
            } elseif ($value === 'false') {
                $value = false;
            } elseif (is_numeric($value)) {
                $value = preg_match('/^[-]?\d+$/', $value) ? (int) $value : (float) $value;
            }

            $partialFilterExpression[(string) $field['name']] = $operator ? ['$' . $operator => $value] : $value;
        }

        return $partialFilterExpression;
    }

    private function setShardKey(ClassMetadata $class, SimpleXMLElement $xmlShardkey) : void
    {
        $attributes = $xmlShardkey->attributes();

        $keys    = [];
        $options = [];
        foreach ($xmlShardkey->{'key'} as $key) {
            $keys[(string) $key['name']] = (string) ($key['order'] ?? 'asc');
        }

        if (isset($attributes['unique'])) {
            $options['unique'] = ((string) $attributes['unique'] === 'true');
        }

        if (isset($attributes['numInitialChunks'])) {
            $options['numInitialChunks'] = (int) $attributes['numInitialChunks'];
        }

        if (isset($xmlShardkey->{'option'})) {
            foreach ($xmlShardkey->{'option'} as $option) {
                $value = (string) $option['value'];
                if ($value === 'true') {
                    $value = true;
                } elseif ($value === 'false') {
                    $value = false;
                } elseif (is_numeric($value)) {
                    $value = preg_match('/^[-]?\d+$/', $value) ? (int) $value : (float) $value;
                }
                $options[(string) $option['name']] = $value;
            }
        }

        $class->setShardKey($keys, $options);
    }

    /**
     * Parses <read-preference> to a format suitable for the underlying driver.
     *
     * list($readPreference, $tags) = $this->transformReadPreference($xml->{read-preference});
     */
    private function transformReadPreference(SimpleXMLElement $xmlReadPreference) : array
    {
        $tags = null;
        if (isset($xmlReadPreference->{'tag-set'})) {
            $tags = [];
            foreach ($xmlReadPreference->{'tag-set'} as $tagSet) {
                $set = [];
                foreach ($tagSet->tag as $tag) {
                    $set[(string) $tag['name']] = (string) $tag['value'];
                }
                $tags[] = $set;
            }
        }
        return [(string) $xmlReadPreference['mode'], $tags];
    }

    /**
     * {@inheritDoc}
     */
    protected function loadMappingFile($file) : array
    {
        $result = [];

        $this->validateSchema($file);

        $xmlElement = simplexml_load_file($file);

        foreach (['document', 'embedded-document', 'mapped-superclass', 'query-result-document', 'gridfs-file'] as $type) {
            if (! isset($xmlElement->$type)) {
                continue;
            }

            foreach ($xmlElement->$type as $documentElement) {
                $documentName          = (string) $documentElement['name'];
                $result[$documentName] = $documentElement;
            }
        }

        return $result;
    }

    private function validateSchema(string $filename) : void
    {
        $document = new DOMDocument();
        $document->load($filename);

        $previousUseErrors = libxml_use_internal_errors(true);

        try {
            libxml_clear_errors();

            if (! $document->schemaValidate(__DIR__ . '/../../../../../../doctrine-mongo-mapping.xsd')) {
                throw MappingException::xmlMappingFileInvalid($filename, $this->formatErrors(libxml_get_errors()));
            }
        } finally {
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    /**
     * @param LibXMLError[] $xmlErrors
     */
    private function formatErrors(array $xmlErrors) : string
    {
        return implode("\n", array_map(static function (LibXMLError $error) : string {
            return sprintf('Line %d:%d: %s', $error->line, $error->column, $error->message);
        }, $xmlErrors));
    }

    private function addGridFSMappings(ClassMetadata $class, SimpleXMLElement $xmlRoot) : void
    {
        if (! $class->isFile) {
            return;
        }

        foreach (self::DEFAULT_GRIDFS_MAPPINGS as $name => $mapping) {
            if (! isset($xmlRoot->{$name})) {
                continue;
            }

            if (isset($xmlRoot->{$name}->attributes()['fieldName'])) {
                $mapping['fieldName'] = (string) $xmlRoot->{$name}->attributes()['fieldName'];
            }

            $this->addFieldMapping($class, $mapping);
        }

        if (! isset($xmlRoot->metadata)) {
            return;
        }

        $xmlRoot->metadata->addAttribute('field', 'metadata');
        $this->addEmbedMapping($class, $xmlRoot->metadata, 'one');
    }
}
