<?php

namespace Oro\Bundle\ApiBundle\Tests\Functional;

use Oro\Bundle\ApiBundle\Request\RequestType;

class GetRestJsonApiWithIncludeFieldsTest extends ApiTestCase
{
    /**
     * FQCN of the entity being used for testing.
     */
    const ENTITY_CLASS = 'Oro\Bundle\UserBundle\Entity\User';

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->initClient(
            [],
            array_replace(
                $this->generateWsseAuthHeader(),
                ['CONTENT_TYPE' => 'application/vnd.api+json']
            )
        );

        parent::setUp();
    }

    /**
     * {@inheritdoc}
     */
    protected function getRequestType()
    {
        return [RequestType::REST, RequestType::JSON_API];
    }

    /**
     * @param array $params
     * @param array $expects
     *
     * @dataProvider getParamsAndExpectation
     */
    public function testGetEntityWithIncludeParameter($params, $expects)
    {
        $entityAlias = $this->entityClassTransformer->transform(self::ENTITY_CLASS);

        // test get list request
        $this->client->request(
            'GET',
            $this->getUrl('oro_rest_api_cget', ['entity' => $entityAlias, 'page[size]' => 1]),
            $params,
            [],
            array_replace(
                $this->generateWsseAuthHeader(),
                ['CONTENT_TYPE' => 'application/vnd.api+json']
            )
        );

        $response = $this->client->getResponse();

        $this->assertApiResponseStatusCodeEquals($response, 200, $entityAlias, 'get list');
        $this->assertEquals($expects, json_decode($response->getContent(), true));
    }

    /**
     * @return array
     */
    public function getParamsAndExpectation()
    {
        return [
            'Filter root entity fields. Only listed should returns without any relations and inclusions' => [
                'params'  => [
                    'fields' => [
                        'users' => 'phone,title,username,email,firstName,middleName,lastName,enabled'
                    ],
                ],
                'expects' => $this->loadExpectation('output_1.yml')
            ],
            'Wrong field names should be skipped' => [
                'params'  => [
                    'include' => 'wrongFieldName1,wrongFieldName2',
                    'fields' => [
                        'users' => 'phone,title,username,email,firstName,middleName,lastName,enabled,wrongFieldName'
                    ],
                ],
                'expects' => $this->loadExpectation('output_1.yml')
            ],
            'Includes should not be added due they are missed in root entity fields' => [
                'params'  => [
                    'include' => 'owner,organization',
                    'fields'  => [
                        'users' => 'phone,title,username,email,firstName,middleName,lastName,enabled'
                    ],
                ],
                'expects' => $this->loadExpectation('output_1.yml')
            ],
            'Included owner and filter it\'s fields (all except createdAt, updatedAt) ' => [
                'params'  => [
                    'include' => 'owner,organization',
                    'fields'  => [
                        'users' => 'phone,title,username,email,firstName,middleName,lastName,enabled,owner',
                        'owner' => 'name,phone,website,email,fax,organization,owner,users'
                    ],
                ],
                'expects' => $this->loadExpectation('output_2.yml')
            ],
            'Owner and Roles not included, so we cannot filter their fields, only relations will be returned' => [
                'params'  => [
                    'include' => 'organization',
                    'fields'  => [
                        'users' => 'username,firstName,lastName,email,organization',
                        'owner' => 'name,phone,website,email,fax',
                        'organization' => 'enabled',
                        'roles' => 'name'
                    ],
                ],
                'expects' => $this->loadExpectation('output_3.yml')
            ],
            'Wrong separator' => [
                'params'  => [
                    'fields' => [
                        'users' => 'phone, title, username,email,firstName,middleName.lastName,enabled'
                    ],
                ],
                'expects' => $this->loadExpectation('output_4.yml')
            ]
        ];
    }
}
