<?php
/*  Frey: ACL & user data storage
 *  Copyright (C) 2016  o.klimenko aka ctizen
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace Frey;

abstract class Model
{
    /**
     * @var Db
     */
    protected $_db;

    /**
     * @var Config
     */
    protected $_config;

    /**
     * @var Meta
     */
    protected $_meta;

    /**
     * @var PersonPrimitive
     */
    protected $_authorizedPerson;

    /**
     * @var array
     */
    protected $_currentAccess = [];

    /**
     * Model constructor.
     * @param IDb $db
     * @param Config $config
     * @param Meta $meta
     * @throws \Exception
     */
    public function __construct(IDb $db, Config $config, Meta $meta)
    {
        $this->_db = $db;
        $this->_config = $config;
        $this->_meta = $meta;
        $this->_authorizedPerson = $this->_fetchAuthorizedPerson();
        $this->_currentAccess = $this->_fetchPersonAccess();
    }

    /**
     * @return PersonPrimitive|null
     * @throws \Exception
     */
    protected function _fetchAuthorizedPerson()
    {
        if (empty($this->_meta->getAuthToken())) {
            return null;
        }

        $persons = PersonPrimitive::findByAuthHash($this->_db, [
            password_hash($this->_meta->getAuthToken(), PASSWORD_DEFAULT)
        ]);

        if (!empty($persons)) {
            return $persons[0];
        }

        return null;
    }

    /**
     * @return array
     * @throws \Exception
     */
    protected function _fetchPersonAccess()
    {
        if (empty($this->_authorizedPerson)) {
            return [];
        }

        return $this->_meta->getCurrentEventId() === null
            ? $this->_getSystemWideRules($this->_authorizedPerson->getId())
            : $this->_getAccessRules($this->_authorizedPerson->getId(), $this->_meta->getCurrentEventId());
    }

    //////////////////////////////////////////////////////////////////
    /// Basic access methods
    /// Stored in base model because they're required at bootstrap.
    /// Exposed at AccessManagement model for external use.
    //////////////////////////////////////////////////////////////////

    const CACHE_TTL_SEC = 10 * 60;

    /**
     * Get apcu cache key for access rules
     *
     * @param $personId
     * @param $eventId
     * @return string
     */
    protected static function _getAccessCacheKey($personId, $eventId)
    {
        return "access_${personId}_${eventId}";
    }

    /**
     * Primary client method, aggregating rules from groups and person.
     * Get array of access rules for person in event.
     * Cached for 10 minutes.
     *
     * @param int $personId
     * @param int $eventId
     * @return array
     * @throws \Exception
     */
    protected function _getAccessRules(int $personId, int $eventId)
    {
        $rules = apcu_fetch($this->_getAccessCacheKey($personId, $eventId));
        if ($rules !== false) {
            return $rules;
        }

        $persons = PersonPrimitive::findById($this->_db, [$personId]);
        if (empty($persons)) {
            throw new EntityNotFoundException('Person with id #' . $personId . ' not found in DB', 401);
        }

        $resultingRules = [];
        foreach ($this->_getGroupAccessRules($persons[0]->getGroupIds(), $eventId) as $rule) {
            $systemWideRuleToBeApplied = empty($rule->getEventsId()) && !isset($resultingRules[$rule->getAclName()]);
            if ($systemWideRuleToBeApplied || !empty($rule->getEventsId()) /* not systemwide rule */) {
                $resultingRules[$rule->getAclName()] = $rule->getAclValue();
            }
        }
        foreach ($this->_getPersonAccessRules($personId, $eventId) as $rule) {
            // Person rules have higher priority than group rules
            $systemWideRuleToBeApplied = empty($rule->getEventsId()) && !isset($resultingRules[$rule->getAclName()]);
            if ($systemWideRuleToBeApplied || !empty($rule->getEventsId()) /* not systemwide rule */) {
                $resultingRules[$rule->getAclName()] = $rule->getAclValue();
            }
        }

        apcu_store($this->_getAccessCacheKey($personId, $eventId), $resultingRules, self::CACHE_TTL_SEC);
        return $resultingRules;
    }

    /**
     * Primary client method, aggregating rules from groups and person.
     * Get array of access rules for person system-wide
     * Cached for 10 minutes.
     *
     * @param int $personId
     * @return array
     * @throws \Exception
     */
    protected function _getSystemWideRules(int $personId)
    {
        $rules = apcu_fetch($this->_getAccessCacheKey($personId, '__system-wide'));
        if ($rules !== false) {
            return $rules;
        }

        $persons = PersonPrimitive::findById($this->_db, [$personId]);
        if (empty($persons)) {
            throw new EntityNotFoundException('Person with id #' . $personId . ' not found in DB', 401);
        }

        $resultingRules = [];
        foreach ($this->_getGroupAccessSystemWideRules($persons[0]->getGroupIds()) as $rule) {
            $resultingRules[$rule->getAclName()] = $rule->getAclValue();
        }
        foreach ($this->_getPersonAccessSystemWideRules($personId) as $rule) {
            // Person rules have higher priority than group rules
            $resultingRules[$rule->getAclName()] = $rule->getAclValue();
        }

        apcu_store($this->_getAccessCacheKey($personId, '__system-wide'), $resultingRules, self::CACHE_TTL_SEC);
        return $resultingRules;
    }

    /**
     * Get single rule for person in event. Hardly relies on cache.
     * Also counts group rules if person belongs to one or more groups.
     * Typically should not be used when more than one value should be retrieved.
     * Returns null if no data found for provided person/event ids or rule name.
     *
     * @param $personId
     * @param $eventId
     * @param $ruleName
     * @return mixed
     * @throws \Exception
     */
    protected function _getRuleValue($personId, $eventId, $ruleName)
    {
        $rules = $this->_getAccessRules($personId, $eventId);
        if (empty($rules[$ruleName])) {
            return null;
        }
        return $rules[$ruleName];
    }

    /**
     * @param $personId
     * @param $eventId
     * @return PersonAccessPrimitive[]
     * @throws \Exception
     */
    protected function _getPersonAccessRules($personId, $eventId)
    {
        return array_filter(
            PersonAccessPrimitive::findByPerson($this->_db, [$personId]),
            function (PersonAccessPrimitive $rule) use ($eventId) {
                return empty($rule->getEventsId()) // system-wide person rules
                    || in_array($eventId, $rule->getEventsId());
            }
        );
    }

    /**
     * @param $groupIds
     * @param $eventId
     * @return GroupAccessPrimitive[]
     * @throws \Exception
     */
    protected function _getGroupAccessRules($groupIds, $eventId)
    {
        return array_filter(
            GroupAccessPrimitive::findByGroup($this->_db, $groupIds),
            function (GroupAccessPrimitive $rule) use ($eventId) {
                return empty($rule->getEventsId()) // system-wide group rules
                    || in_array($eventId, $rule->getEventsId());
            }
        );
    }

    /**
     * @param $personId
     * @return PersonAccessPrimitive[]
     * @throws \Exception
     */
    protected function _getPersonAccessSystemWideRules($personId)
    {
        return array_filter(
            PersonAccessPrimitive::findByPerson($this->_db, [$personId]),
            function (PersonAccessPrimitive $rule) {
                return empty($rule->getEventsId());
            }
        );
    }

    /**
     * @param $groupIds
     * @return GroupAccessPrimitive[]
     * @throws \Exception
     */
    protected function _getGroupAccessSystemWideRules($groupIds)
    {
        return array_filter(
            GroupAccessPrimitive::findByGroup($this->_db, $groupIds),
            function (GroupAccessPrimitive $rule) {
                return empty($rule->getEventsId());
            }
        );
    }
}
