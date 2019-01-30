<?php
/**
 * File containing the eZ\Publish\API\Repository\Values\User\Limitation\ParentContentTypeLimitation class.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 * @version //autogentag//
 */

namespace eZ\Publish\Core\Limitation;

use eZ\Publish\API\Repository\Exceptions\NotFoundException as APINotFoundException;
use eZ\Publish\API\Repository\Values\ValueObject;
use eZ\Publish\API\Repository\Values\User\User as APIUser;
use eZ\Publish\API\Repository\Values\Content\Content;
use eZ\Publish\API\Repository\Values\Content\ContentCreateStruct;
use eZ\Publish\API\Repository\Values\Content\VersionInfo;
use eZ\Publish\API\Repository\Values\Content\ContentInfo;
use eZ\Publish\API\Repository\Values\Content\Location;
use eZ\Publish\API\Repository\Values\Content\LocationCreateStruct;
use eZ\Publish\Core\Base\Exceptions\InvalidArgumentException;
use eZ\Publish\Core\Base\Exceptions\InvalidArgumentType;
use eZ\Publish\API\Repository\Values\User\Limitation\ParentContentTypeLimitation as APIParentContentTypeLimitation;
use eZ\Publish\API\Repository\Values\User\Limitation as APILimitationValue;
use eZ\Publish\SPI\Limitation\Type as SPILimitationTypeInterface;
use eZ\Publish\Core\FieldType\ValidationError;
use eZ\Publish\SPI\Persistence\Content\Location as SPILocation;

/**
 * ParentContentTypeLimitation is a Content limitation
 */
class ParentContentTypeLimitationType extends AbstractPersistenceLimitationType implements SPILimitationTypeInterface
{
    /**
     * Accepts a Limitation value and checks for structural validity.
     *
     * Makes sure LimitationValue object and ->limitationValues is of correct type.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException If the value does not match the expected type/structure
     *
     * @param \eZ\Publish\API\Repository\Values\User\Limitation $limitationValue
     */
    public function acceptValue( APILimitationValue $limitationValue )
    {
        if ( !$limitationValue instanceof APIParentContentTypeLimitation )
        {
            throw new InvalidArgumentType( "\$limitationValue", "APIParentContentTypeLimitation", $limitationValue );
        }
        else if ( !is_array( $limitationValue->limitationValues ) )
        {
            throw new InvalidArgumentType( "\$limitationValue->limitationValues", "array", $limitationValue->limitationValues );
        }

        foreach ( $limitationValue->limitationValues as $key => $id )
        {
            if ( !is_string( $id ) && !is_int( $id ) )
            {
                throw new InvalidArgumentType( "\$limitationValue->limitationValues[{$key}]", "int|string", $id );
            }
        }
    }

    /**
     * Makes sure LimitationValue->limitationValues is valid according to valueSchema().
     *
     * Make sure {@link acceptValue()} is checked first!
     *
     * @param \eZ\Publish\API\Repository\Values\User\Limitation $limitationValue
     *
     * @return \eZ\Publish\SPI\FieldType\ValidationError[]
     */
    public function validate( APILimitationValue $limitationValue )
    {
        $validationErrors = array();
        foreach ( $limitationValue->limitationValues as $key => $id )
        {
            try
            {
                $this->persistence->contentTypeHandler()->load( $id );
            }
            catch ( APINotFoundException $e )
            {
                $validationErrors[] = new ValidationError(
                    "limitationValues[%key%] => '%value%' does not exist in the backend",
                    null,
                    array(
                        "value" => $id,
                        "key" => $key
                    )
                );
            }
        }
        return $validationErrors;
    }

    /**
     * Create the Limitation Value
     *
     * @param mixed[] $limitationValues
     *
     * @return \eZ\Publish\API\Repository\Values\User\Limitation
     */
    public function buildValue( array $limitationValues )
    {
        return new APIParentContentTypeLimitation( array( 'limitationValues' => $limitationValues ) );
    }

    /**
     * Evaluate permission against content & target(placement/parent/assignment)
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException If any of the arguments are invalid
     *         Example: If LimitationValue is instance of ContentTypeLimitationValue, and Type is SectionLimitationType.
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException If value of the LimitationValue is unsupported
     *         Example if OwnerLimitationValue->limitationValues[0] is not one of: [ 1,  2 ]
     *
     * @param \eZ\Publish\API\Repository\Values\User\Limitation $value
     * @param \eZ\Publish\API\Repository\Values\User\User $currentUser
     * @param \eZ\Publish\API\Repository\Values\ValueObject $object
     * @param \eZ\Publish\API\Repository\Values\ValueObject[]|null $targets The context of the $object, like Location of Content, if null none where provided by caller
     *
     * @return boolean
     */
    public function evaluate( APILimitationValue $value, APIUser $currentUser, ValueObject $object, array $targets = null )
    {
        if ( !$value instanceof APIParentContentTypeLimitation )
            throw new InvalidArgumentException( '$value', 'Must be of type: APIParentContentTypeLimitation' );

        if ( $object instanceof ContentCreateStruct )
        {
            return $this->evaluateForContentCreateStruct( $value, $targets );
        }
        else if ( $object instanceof Content )
        {
            $object = $object->getVersionInfo()->getContentInfo();
        }
        else if ( $object instanceof VersionInfo )
        {
            $object = $object->getContentInfo();
        }
        else if ( !$object instanceof ContentInfo )
        {
            throw new InvalidArgumentException(
                "\$object",
                "Must be of type: ContentCreateStruct, Content, VersionInfo or ContentInfo"
            );
        }

        // Try to load locations if no targets were provided
        if ( empty( $targets ) )
        {
            if ( $object->published )
            {
                $targets = $this->persistence->locationHandler()->loadLocationsByContent( $object->id );
            }
            else
            {
                // @todo Need support for draft locations to to work correctly
                $targets = $this->persistence->locationHandler()->loadParentLocationsForDraftContent( $object->id );
            }
        }

        // If targets is empty/null return false as user does not have access
        // to content w/o location with this limitation
        if ( empty( $targets ) )
        {
            return false;
        }

        foreach ( $targets as $target )
        {
            if ( $target instanceof LocationCreateStruct )
            {
                $target = $this->persistence->locationHandler()->load( $target->parentLocationId );
            }

            if ( $target instanceof Location )
            {
                $contentTypeId = $target->getContentInfo()->contentTypeId;
            }
            else if ( $target instanceof SPILocation )
            {
                $spiContentInfo = $this->persistence->contentHandler()->loadContentInfo( $target->contentId );
                $contentTypeId = $spiContentInfo->contentTypeId;
            }
            else
            {
                throw new InvalidArgumentException(
                    '$targets',
                    'Must contain objects of type: Location or LocationCreateStruct'
                );
            }

            if ( !in_array( $contentTypeId, $value->limitationValues ) )
            {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate permissions for ContentCreateStruct against LocationCreateStruct placements.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException If $targets does not contain
     *         objects of type LocationCreateStruct
     *
     * @param \eZ\Publish\API\Repository\Values\User\Limitation $value
     * @param array $targets
     *
     * @return bool
     */
    protected function evaluateForContentCreateStruct( APILimitationValue $value, array $targets )
    {
        // If targets is empty/null return false as user does not have access
        // to content w/o location with this limitation
        if ( empty( $targets ) )
        {
            return false;
        }

        foreach ( $targets as $target )
        {
            if ( !$target instanceof LocationCreateStruct )
            {
                throw new InvalidArgumentException(
                    '$targets',
                    'If $object is ContentCreateStruct must contain objects of type: LocationCreateStruct'
                );
            }

            $location = $this->persistence->locationHandler()->load( $target->parentLocationId );
            $contentTypeId = $this->persistence->contentHandler()->loadContentInfo( $location->contentId )->contentTypeId;

            if ( !in_array( $contentTypeId, $value->limitationValues ) )
            {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns Criterion for use in find() query
     *
     * @param \eZ\Publish\API\Repository\Values\User\Limitation $value
     * @param \eZ\Publish\API\Repository\Values\User\User $currentUser
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Query\CriterionInterface
     */
    public function getCriterion( APILimitationValue $value, APIUser $currentUser )
    {
        throw new \eZ\Publish\API\Repository\Exceptions\NotImplementedException( __METHOD__ );
    }

    /**
     * Returns info on valid $limitationValues
     *
     * @return mixed[]|int In case of array, a hash with key as valid limitations value and value as human readable name
     *                     of that option, in case of int on of VALUE_SCHEMA_ constants.
     */
    public function valueSchema()
    {
        throw new \eZ\Publish\API\Repository\Exceptions\NotImplementedException( __METHOD__ );
    }
}
