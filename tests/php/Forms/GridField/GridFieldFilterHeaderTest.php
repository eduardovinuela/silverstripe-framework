<?php

namespace SilverStripe\Forms\Tests\GridField;

use LogicException;
use ReflectionMethod;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\Forms\Tests\GridField\GridFieldFilterHeaderTest\Cheerleader;
use SilverStripe\Forms\Tests\GridField\GridFieldFilterHeaderTest\CheerleaderHat;
use SilverStripe\Forms\Tests\GridField\GridFieldFilterHeaderTest\Mom;
use SilverStripe\Forms\Tests\GridField\GridFieldFilterHeaderTest\NonDataObject;
use SilverStripe\Forms\Tests\GridField\GridFieldFilterHeaderTest\Team;
use SilverStripe\Forms\Tests\GridField\GridFieldFilterHeaderTest\TeamGroup;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Filters\PartialMatchFilter;
use SilverStripe\ORM\Filters\SearchFilter;
use SilverStripe\ORM\Search\BasicSearchContext;
use SilverStripe\ORM\Search\SearchContext;
use SilverStripe\View\ArrayData;

class GridFieldFilterHeaderTest extends SapphireTest
{

    /**
     * @var ArrayList
     */
    protected $list;

    /**
     * @var GridField
     */
    protected $gridField;

    /**
     * @var Form
     */
    protected $form;

    /**
     * @var GridFieldFilterHeader
     */
    protected $component;

    protected static $fixture_file = 'GridFieldFilterHeaderTest.yml';

    protected static $extra_dataobjects = [
        Team::class,
        TeamGroup::class,
        Cheerleader::class,
        CheerleaderHat::class,
        Mom::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->list = new DataList(Team::class);
        $config = GridFieldConfig_RecordEditor::create()->addComponent(new GridFieldFilterHeader());
        $this->gridField = new GridField('testfield', 'testfield', $this->list, $config);
        $this->form = new Form(null, 'Form', new FieldList([$this->gridField]), new FieldList());
        $this->component = $this->gridField->getConfig()->getComponentByType(GridFieldFilterHeader::class);
    }

    /**
     * Tests that the appropriate filter headers are generated
     *
     */
    public function testRenderHeaders()
    {
        $htmlFragment = $this->component->getHTMLFragments($this->gridField);

        // Check that the output is the new search field
        $this->assertStringContainsString('<div class="search-holder grid-field__search-holder grid-field__search-holder--hidden"', $htmlFragment['before']);
        $this->assertStringContainsString('Open search and filter', $htmlFragment['buttons-before-right']);
    }

    public function testSearchFieldSchema()
    {
        $searchSchema = json_decode($this->component->getSearchFieldSchema($this->gridField) ?? '');
        $modelClass = $this->gridField->getModelClass();
        /** @var DataObject $obj */
        $obj = new $modelClass();

        $this->assertEquals('field/testfield/schema/SearchForm', $searchSchema->formSchemaUrl);
        $this->assertEquals($obj->getGeneralSearchFieldName(), $searchSchema->name);
        $this->assertEquals('Search "Teams"', $searchSchema->placeholder);
        $this->assertEquals(new \stdClass, $searchSchema->filters);

        $request = new HTTPRequest(
            'POST',
            'field/testfield',
            [],
            [
                'filter' => [
                    'testfield' => [
                        'Name' => 'test',
                        'City' => 'place'
                    ]
                ],
            ]
        );
        $this->gridField->setRequest($request);
        $searchSchema = json_decode($this->component->getSearchFieldSchema($this->gridField) ?? '');
        $modelClass = $this->gridField->getModelClass();
        /** @var DataObject $obj */
        $obj = new $modelClass();

        $this->assertEquals('field/testfield/schema/SearchForm', $searchSchema->formSchemaUrl);
        $this->assertEquals($obj->getGeneralSearchFieldName(), $searchSchema->name);
        $this->assertEquals('Search "Teams"', $searchSchema->placeholder);
        $this->assertEquals('test', $searchSchema->filters->Search__Name);
        $this->assertEquals('place', $searchSchema->filters->Search__City);
        $this->assertEquals('testfield', $searchSchema->gridfield);
    }

    /**
     * Tests the private method that returns the placeholder for the search field
     */
    public function testGetPlaceHolder()
    {
        $gridField = new GridField('test');
        $filterHeader = new GridFieldFilterHeader();
        $reflectionGetPlaceHolder = new ReflectionMethod($filterHeader, 'getPlaceHolder');
        $reflectionGetPlaceHolder->setAccessible(true);

        // No explicit placeholder or model i18n_plural_name method
        $this->assertSame('Search "ArrayData"', $reflectionGetPlaceHolder->invoke($filterHeader, new ArrayData()));

        // No explicit placeholder, but model has i18n_plural_name method
        $model = new DataObject();
        $this->assertSame('Search "' . $model->i18n_plural_name() . '"', $reflectionGetPlaceHolder->invoke($filterHeader, $model));

        // Explicit placeholder is set, which overrides both of the above cases
        $filterHeader->setPlaceHolderText('This is the text');
        $this->assertSame('This is the text', $reflectionGetPlaceHolder->invoke($filterHeader, $model));
        $this->assertSame('This is the text', $reflectionGetPlaceHolder->invoke($filterHeader, new ArrayData()));
    }

    public function testHandleActionReset()
    {
        // Init Grid state with some pre-existing filters
        $state = $this->gridField->State;
        $state->GridFieldFilterHeader = [];
        $state->GridFieldFilterHeader->Columns = [];
        $state->GridFieldFilterHeader->Columns->Name = 'test';

        $this->component->handleAction(
            $this->gridField,
            'reset',
            [],
            '{"GridFieldFilterHeader":{"Columns":{"Name":"test"}}}'
        );

        $this->assertEmpty(
            $state->GridFieldFilterHeader->Columns->toArray(),
            'GridFieldFilterHeader::handleAction resets the gridstate filter when the user resets the search.'
        );
    }

    public function testGetSearchForm()
    {
        $searchForm = $this->component->getSearchForm($this->gridField);
        $this->assertTrue($searchForm instanceof Form);
        $fields = $searchForm->Fields()->toArray();
        $this->assertEquals('Search__q', $fields[0]->Name);
        $this->assertEquals('Search__Name', $fields[1]->Name);
        $this->assertEquals('Search__City', $fields[2]->Name);
        $this->assertEquals('Search__Cheerleader__Hat__Colour', $fields[3]->Name);
        $this->assertEquals('TeamsSearchForm', $searchForm->Name);
        $this->assertTrue($searchForm->hasExtraClass('cms-search-form'));
        foreach ($fields as $field) {
            $this->assertTrue($field->hasExtraClass('stacked'));
            $this->assertTrue($field->hasExtraClass('no-change-track'));
        }
    }

    public function testCustomSearchField()
    {
        $searchSchema = json_decode($this->component->getSearchFieldSchema($this->gridField));
        $modelClass = $this->gridField->getModelClass();
        $obj = new $modelClass();
        $this->assertEquals($obj->getGeneralSearchFieldName(), $searchSchema->name);

        Config::modify()->set(Team::class, 'general_search_field', 'CustomSearch');
        $searchSchema = json_decode($this->component->getSearchFieldSchema($this->gridField));
        $this->assertEquals('CustomSearch', $searchSchema->name);

        $this->component->setSearchField('ReallyCustomSearch');
        $searchSchema = json_decode($this->component->getSearchFieldSchema($this->gridField));
        $this->assertEquals('ReallyCustomSearch', $searchSchema->name);

        $this->assertEquals('ReallyCustomSearch', $this->component->getSearchField());
    }

    public function testCanFilterAnyColumns()
    {
        $gridField = $this->gridField;
        $filterHeader = $gridField->getConfig()->getComponentByType(GridFieldFilterHeader::class);

        // test that you can filter by something if searchable_fields is not defined
        // silverstripe will scaffold db columns that are in the gridfield to be
        // searchable by default
        Config::modify()->remove(Team::class, 'searchable_fields');
        $this->assertTrue($filterHeader->canFilterAnyColumns($gridField));

        // test that you can filterBy if searchable_fields is defined
        Config::modify()->set(Team::class, 'searchable_fields', ['Name']);
        $this->assertTrue($filterHeader->canFilterAnyColumns($gridField));

        // test that you can filterBy if searchable_fields even if it is not a legit field
        // this is because we're making a blind assumption it will be filterable later in a SearchContext
        Config::modify()->set(Team::class, 'searchable_fields', ['WhatIsThis']);
        $this->assertTrue($filterHeader->canFilterAnyColumns($gridField));

        // test that you cannot filter by non-db field when it falls back to summary_fields
        Config::modify()->remove(Team::class, 'searchable_fields');
        Config::modify()->set(Team::class, 'summary_fields', ['MySummaryField']);
        $this->assertFalse($filterHeader->canFilterAnyColumns($gridField));
    }

    public function testCanFilterAnyColumnsNonDataObject()
    {
        $list = new ArrayList([
            new NonDataObject([]),
        ]);
        $config = GridFieldConfig::create()->addComponent(new GridFieldFilterHeader());
        $gridField = new GridField('testfield', 'testfield', $list, $config);
        $form = new Form(null, 'Form', new FieldList([$gridField]), new FieldList());
        /** @var GridFieldFilterHeader $component */
        $component = $gridField->getConfig()->getComponentByType(GridFieldFilterHeader::class);

        $this->assertFalse($component->canFilterAnyColumns($gridField));
    }

    public function testRenderHeadersNonDataObject()
    {
        $list = new ArrayList([
            new NonDataObject([]),
        ]);
        $config = GridFieldConfig::create()->addComponent(new GridFieldFilterHeader());
        $gridField = new GridField('testfield', 'testfield', $list, $config);
        $form = new Form(null, 'Form', new FieldList([$gridField]), new FieldList());
        /** @var GridFieldFilterHeader $component */
        $component = $gridField->getConfig()->getComponentByType(GridFieldFilterHeader::class);
        $htmlFragment = $component->getHTMLFragments($gridField);

        $this->assertNull($htmlFragment);
    }

    public function testGetDisplayFieldsThrowsException()
    {
        $component = new GridFieldFilterHeader();
        $gridField = new GridField('dummy', 'dummy', new ArrayList());
        $modelClass = ArrayData::class;
        $gridField->setModelClass($modelClass);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Cannot dynamically instantiate SearchContext. Pass the SearchContext to setSearchContext()'
            . " or implement a getDefaultSearchContext() method on $modelClass"
        );

        $component->getSearchContext($gridField);
    }

    public function testGetBasicSearchContext(): void
    {
        $arrayList = new ArrayList();
        $arrayList->setDataClass(Team::class);
        $arrayListFilter = new GridFieldFilterHeader();
        $arrayListGridField = new GridField('dummy', 'dummy', $arrayList);
        $arrayListSearchContext = $arrayListFilter->getSearchContext($arrayListGridField);

        $dataList = Team::get();
        $dataListFilter = new GridFieldFilterHeader();
        $dataListGridField = new GridField('dummy', 'dummy', $dataList);
        $dataListSearchContext = $dataListFilter->getSearchContext($dataListGridField);

        $this->assertInstanceOf(
            BasicSearchContext::class,
            $arrayListSearchContext,
            'We expect a basic search context as our GridField list is provided via ArrayList'
        );

        $this->assertNotInstanceOf(
            BasicSearchContext::class,
            $dataListSearchContext,
            'We expect a regular search context as our GridField list is provided via DataList'
        );

        $arrayListSearchFields = $arrayListSearchContext
            ->getSearchFields()
            ->column('Name');

        $dataListSearchFields = $dataListSearchContext
            ->getSearchFields()
            ->column('Name');

        $this->assertSame(
            $arrayListSearchFields,
            $dataListSearchFields,
            'We expect the search fields to be the same regardless of how data is provided to the GridField'
        );

        $arrayListFilters = $arrayListSearchContext->getFilters();
        $dataListFilters = $dataListSearchContext->getFilters();

        $getFilterName = static function (SearchFilter $filter): string {
            return $filter->getName();
        };
        $arrayListSearchFilterNames = array_map($getFilterName, $arrayListFilters);
        $dataListSearchFilterNames = array_map($getFilterName, $dataListFilters);
        $arrayListSearchFilterNames = array_values($arrayListSearchFilterNames);
        $dataListSearchFilterNames = array_values($dataListSearchFilterNames);

        $this->assertSame(
            $arrayListSearchFilterNames,
            $dataListSearchFilterNames,
            'We expect the search filters to be the same regardless of how data is provided to the GridField'
        );

        $getFilterType = static function (SearchFilter $filter): string {
            return $filter::class;
        };
        $arrayListSearchFilterTypes = array_map($getFilterType, $arrayListFilters);
        $arrayListSearchFilterTypes = array_unique($arrayListSearchFilterTypes);

        $this->assertCount(1, $arrayListSearchFilterTypes, 'We expect all filters to be of the same type');
        $arrayListSearchFilterType = array_shift($arrayListSearchFilterTypes);

        $this->assertEquals(
            PartialMatchFilter::class,
            $arrayListSearchFilterType,
            'We expect partial match filters'
        );
    }
}
