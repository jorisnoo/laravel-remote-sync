<?php

return [

    'confirm' => [
        'pull' => 'Replace your local :scope with data from [:name]?',
        'push' => 'Push your local :scope to [:name]? This will OVERWRITE remote data. Type "yes" to continue',
        'prune' => 'Delete :count snapshot?|Delete :count snapshots?',
        'typed_yes_suffix' => 'Type "yes" to continue',
        'validation' => 'Type "yes" to confirm',
        'accept_host_key' => 'Do you want to trust this host [:host] and continue connecting?',
        'continue_without_driver' => 'Could not detect the remote database driver. Continue anyway?',
    ],

    'remote' => [
        'label' => 'Select remote environment',
    ],

    'operations' => [
        'pull_label' => 'What would you like to pull?',
        'push_label' => 'What would you like to push?',
        'database' => 'Database',
        'files' => 'Files',
    ],

];
