<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

class PersistenceException extends \RuntimeException {}

final class PersistenceConflictException extends PersistenceException {}

final class PersistenceNotFoundException extends PersistenceException {}

final class PersistenceInvalidTransitionException extends PersistenceException {}

final class PersistenceOwnershipMismatchException extends PersistenceException {}

final class PersistenceValidationException extends PersistenceException {}
