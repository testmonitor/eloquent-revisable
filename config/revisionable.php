<?php

use App\Models\User;
use TestMonitor\Revisable\Generators\VersionNameGenerator;
use TestMonitor\Revisable\Models\Revision;

return [

    /*
     * The model used to store revisions. You may swap this for a custom model,
     * as long as it extends the default Revision model.
     */
    'revision_model' => Revision::class,

    /*
     * The generator class used to produce a name for each revision.
     * Set to null to disable automatic naming.
     */
    'name_generator' => VersionNameGenerator::class,

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * The user model that will be associated with each revision.
     */
    'user_model' => User::class,

];
