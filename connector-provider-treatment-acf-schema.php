<?php
/**
 * Plugin Name: Dermatology EEAT Master Schema
 * Description: Version 8.8 - NO TRUNCATION. Full Provider E-E-A-T + Strict Taxonomy Forks for Medical/Aesthetic.
 * Version: 8.8
 * Author: DIGITAL ATLAS + Gemini AI Collaboration
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Options Page Initialization
add_action('acf/init', function() {
    if( function_exists('acf_add_options_page') ) {
        acf_add_options_page(array(
            'page_title'    => 'Clinic Schema Settings',
            'menu_title'    => 'Clinic Schema',
            'menu_slug'     => 'clinic-schema-settings',
            'capability'    => 'edit_posts',
            'redirect'      => false,
            'icon_url'      => 'dashicons-hospital'
        ));
    }
});

// 2. Health Dashboard Widget
add_action('wp_dashboard_setup', function() {
    wp_add_dashboard_widget('gd_schema_health_widget', 'Schema E-E-A-T Health Check', 'gd_render_schema_health_widget');
});

function gd_render_schema_health_widget() {
    $providers = get_posts(['post_type' => 'team', 'numberposts' => -1]);
    $missing_npi = [];
    foreach ($providers as $p) { if ( empty(get_field('provider_npi', $p->ID)) ) { $missing_npi[] = get_the_title($p->ID); } }
    $conditions = get_posts(['post_type' => 'treatment', 'numberposts' => -1, 'post_status' => 'publish']);
    $missing_sameas = [];
    foreach ($conditions as $c) { 
        $sources = get_field('source_of_truth', $c->ID); 
        if ( empty($sources) || (is_array($sources) && empty($sources[0]['same_as'])) ) { $missing_sameas[] = get_the_title($c->ID); } 
    }
    echo '<div style="padding:10px;">';
    if ( empty($missing_npi) && empty($missing_sameas) ) { echo '<p style="color:green;"><strong>✔ All E-E-A-T signals are looking strong!</strong></p>'; } 
    else {
        if ( !empty($missing_npi) ) { echo '<p><span class="dashicons dashicons-warning" style="color:#d63638;"></span> <strong>Missing NPI:</strong><br><small>' . implode(', ', $missing_npi) . '</small></p>'; }
        if ( !empty($missing_sameas) ) { echo '<p><span class="dashicons dashicons-warning" style="color:#d63638;"></span> <strong>Missing SameAs Links:</strong><br><small>' . implode(', ', $missing_sameas) . '</small></p>'; }
    }
    echo '<hr><p><a href="' . admin_url('admin.php?page=clinic-schema-settings') . '" class="button">Update Clinic Settings</a></p></div>';
}

// 3. Schema Injection Logic
add_action( 'wp_head', 'gd_inject_eeat_schema' );

function gd_inject_eeat_schema() {
    $post_id = get_the_ID();
    $clinic_name = get_field('clinic_name', 'option');
    $custom_fragment = get_field('clinic_fragment_id', 'option');
    $clinic_id = get_home_url() . "#" . ($custom_fragment ?: 'main-clinic');

    // --- SHARED HELPER: MAP CONDITION NODE ---
    $map_condition_node = function($id) use ($clinic_id) {
        if (!$id) return null;
        $c_node = [
            "@type" => "MedicalCondition",
            "@id" => get_permalink($id) . "#condition",
            "name" => get_the_title($id),
            "url" => get_permalink($id),
            "provider" => ["@type" => "MedicalClinic", "@id" => $clinic_id],
            "relevantSpecialty" => ["@type" => "MedicalSpecialty", "name" => "Dermatology"],
            "signOrSymptom" => [],
            "possibleTreatment" => [],
            "sameAs" => [],
            "alternateName" => []
        ];

        $symptoms = get_field('condition_symptoms', $id);
        if (is_array($symptoms)) { foreach ($symptoms as $s) { if (!empty($s['symptom_name'])) $c_node["signOrSymptom"][] = ["@type" => "MedicalSignOrSymptom", "name" => $s['symptom_name']]; } }
        
        $treatments = get_field('possible_treatments_repeater', $id);
        if (is_array($treatments)) { 
            foreach ($treatments as $row) { 
                $t_raw = isset($row['internal_treatment']) ? $row['internal_treatment'] : null; 
                $t_id = (is_object($t_raw) && isset($t_raw->ID)) ? $t_raw->ID : (is_array($t_raw) && isset($t_raw['ID']) ? $t_raw['ID'] : (is_numeric($t_raw) ? (int)$t_raw : 0));
                if ($t_id > 0) {
                    $c_node["possibleTreatment"][] = ["@type" => "MedicalProcedure", "name" => get_the_title($t_id), "url" => get_permalink($t_id)]; 
                } elseif (!empty($row['manual_treatment_name'])) {
                    $c_node["possibleTreatment"][] = ["@type" => "MedicalProcedure", "name" => $row['manual_treatment_name'], "url" => !empty($row['manual_treatment_url']) ? $row['manual_treatment_url'] : get_permalink($id)];
                }
            } 
        }

        $sources = get_field('source_of_truth', $id);
        if (is_array($sources)) { foreach ($sources as $s) { if (!empty($s['same_as'])) $c_node["sameAs"][] = $s['same_as']; } }
        $alts = get_field('alternate_names', $id);
        if (!empty($alts)) { $c_node["alternateName"] = array_filter(array_map('trim', explode("\n", $alts))); }
        return $c_node;
    };

    // --- FORK: TREATMENT POST TYPE LOGIC ---
    if ( is_singular( array( 'treatment', 'service' ) ) ) {
        $categories = wp_get_post_terms( $post_id, 'treatment-category', array( 'fields' => 'slugs' ) );
        $is_aesthetic = in_array( 'aesthetics', $categories );
        $is_medical   = in_array( 'medical', $categories );
        $linked_providers = get_field('treatment_providers', $post_id);

        $treatment_schema = ["@context" => "https://schema.org", "@type" => "MedicalWebPage", "name" => get_the_title($post_id) . " at " . ($clinic_name ?: get_bloginfo('name')), "description" => get_field('procedure_description', $post_id) ?: get_the_excerpt(), "lastReviewed" => get_the_modified_date('c'), "mainEntity" => []];

        if ( $is_aesthetic ) {
            $procedure_entity = ["@type" => get_field('medical_procedure_type', $post_id) ?: "MedicalProcedure", "@id" => get_permalink($post_id) . "#procedure", "name" => get_field('clinical_name', $post_id) ?: get_the_title($post_id), "provider" => ["@type" => "MedicalClinic", "@id" => $clinic_id]];
            $p_alts = get_field('alternate_names', $post_id); if (!empty($p_alts)) $procedure_entity["alternateName"] = array_filter(array_map('trim', explode("\n", $p_alts)));
            $treatment_schema["mainEntity"][] = $procedure_entity;
            $condition_repeater = get_field('medical_conditions_repeater', $post_id);
            if (is_array($condition_repeater)) { 
                foreach ($condition_repeater as $row) { 
                    $c_raw = isset($row['internal_treatment']) ? $row['internal_treatment'] : null;
                    $c_id = (is_object($c_raw) && isset($c_raw->ID)) ? $c_raw->ID : (is_array($c_raw) && isset($c_raw['ID']) ? $c_raw['ID'] : (is_numeric($c_raw) ? (int)$c_raw : 0));
                    if ($c_id > 0) $treatment_schema["mainEntity"][] = $map_condition_node($c_id);
                } 
            }
        } elseif ( $is_medical ) {
            $treatment_schema["mainEntity"][] = $map_condition_node($post_id);
        }

        if ( $linked_providers ) {
            foreach ($linked_providers as $p_id) { 
                $p_t = get_field('provider_type', $p_id) ?: 'Person'; $npi = get_field('provider_npi', $p_id); $p_o = ["@type" => $p_t, "name" => get_the_title($p_id), "url" => get_permalink($p_id)]; 
                if (!empty($npi)) { if ($p_t === 'Physician') $p_o["usNPI"] = $npi; else $p_o["identifier"] = ["@type" => "PropertyValue", "name" => "NPI", "value" => $npi]; } 
                $treatment_schema["provider"][] = $p_o; 
            }
        }
        echo "\n<script type='application/ld+json'>" . json_encode($treatment_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }

    // --- FORK: PROVIDER (TEAM) POST TYPE LOGIC ---
    if ( is_singular('team') ) {
        $p_type = get_field('provider_type', $post_id) ?: 'Person';
        $npi = get_field('provider_npi', $post_id);
        $provider_entity = ["@type" => ["Person", $p_type], "@id" => get_permalink($post_id) . "#provider", "name" => get_the_title($post_id), "jobTitle" => get_field('exact_job_title', $post_id), "url" => get_permalink($post_id), "medicalSpecialty" => get_field('medical_specialties', $post_id) ?: ["Dermatology"], "worksFor" => ["@type" => "MedicalClinic", "@id" => $clinic_id], "sameAs" => []];

        // 1. Credentials
        $credentials = get_field('educational_occupational_credential', $post_id);
        if ( is_array($credentials) ) {
            $provider_entity["hasCredential"] = [];
            foreach ( $credentials as $cred ) {
                $c_obj = ["@type" => "EducationalOccupationalCredential", "name" => $cred['name'], "credentialCategory" => $cred['credential_category']];
                if ( is_array($cred['recognized_by']) ) { foreach ( $cred['recognized_by'] as $org ) { $c_obj["recognizedBy"] = ["@type" => !empty($org['type']) ? $org['type'] : "EducationalOrganization", "name" => $org['name']]; } }
                $provider_entity["hasCredential"][] = $c_obj;
            }
        }

        // 2. Education
        $edu_history = get_field('education_history', $post_id);
        if ( is_array($edu_history) ) { foreach ( $edu_history as $edu ) { if ( !empty($edu['organization_name']) ) { $provider_entity["alumniOf"][] = ["@type" => "EducationalOrganization", "name" => $edu['organization_name']]; } } }

        // 3. Hospital Affiliation
        $affiliations = get_field('affiliations', $post_id);
        if ( is_array($affiliations) ) { foreach ( $affiliations as $aff ) { if ( !empty($aff['name']) ) { $provider_entity["affiliation"][] = ["@type" => "Hospital", "name" => $aff['name']]; } } }

        // 4. Honorifics, knowsAbout, sameAs
        $prefixes = get_field('honorific_prefix', $post_id); if ($prefixes) $provider_entity["honorificPrefix"] = is_array($prefixes) ? implode(', ', $prefixes) : $prefixes;
        $suffixes = get_field('honorific_suffix', $post_id); if ($suffixes) $provider_entity["honorificSuffix"] = is_array($suffixes) ? implode(', ', $suffixes) : $suffixes;
        $knows = get_field('knows_about', $post_id); if ($knows) $provider_entity["knowsAbout"] = array_filter(array_map('trim', explode("\n", $knows)));
        $sa = get_field('provider_profiles_sameas', $post_id); if (is_array($sa)) { foreach ($sa as $s) { if (!empty($s['url'])) $provider_entity["sameAs"][] = $s['url']; } }

        if (!empty($npi)) { if ($p_type === 'Physician') $provider_entity["usNPI"] = $npi; else $provider_entity["identifier"] = ["@type" => "PropertyValue", "name" => "NPI", "value" => $npi]; }

        echo "\n<script type='application/ld+json'>" . json_encode(["@context" => "https://schema.org", "@graph" => [["@type" => "MedicalWebPage", "name" => get_the_title($post_id)], $provider_entity]], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }

    // --- FORK: GLOBAL CLINIC LOGIC ---
    if ( is_front_page() || ( is_page() && is_array(get_field('schema_display_pages', 'option')) && in_array(get_the_ID(), get_field('schema_display_pages', 'option')) ) ) {
        $logo = get_field('logo', 'option');
        $clinic_schema = ["@context" => "https://schema.org", "@type" => "MedicalClinic", "@id" => $clinic_id, "name" => $clinic_name ?: get_bloginfo('name'), "address" => ["@type" => "PostalAddress", "streetAddress" => get_field('clinic_street', 'option'), "addressLocality" => get_field('clinic_city', 'option'), "addressRegion" => get_field('clinic_state', 'option'), "postalCode" => get_field('clinic_zip', 'option')], "telephone" => get_field('clinic_phone', 'option'), "url" => get_home_url()];
        if ($logo) $clinic_schema["logo"] = ["@type" => "ImageObject", "url" => is_array($logo) ? $logo['url'] : wp_get_attachment_url($logo)];
        echo "\n<script type='application/ld+json'>" . json_encode($clinic_schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    }
}