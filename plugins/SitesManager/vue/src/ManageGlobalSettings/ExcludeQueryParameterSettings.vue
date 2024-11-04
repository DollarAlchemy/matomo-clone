<!--
  Matomo - free/libre analytics platform

  @link    https://matomo.org
  @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
-->

<template>
  <div class="siteManagerGlobalExcludedUrlParameters">
      <div id="excludedQueryParametersGlobalHelp" class="inline-help-node">
        <div>
          {{ translate('SitesManager_ListOfQueryParametersToExclude', '/^sess.*|.*[dD]ate$/') }}

          <br/><br/>

          {{ translate(
          'SitesManager_PiwikWillAutomaticallyExcludeCommonSessionParameters',
          'phpsessid, sessionid, ...',
        ) }}
        </div>
      </div>

      <div id="excludedQueryParametersGlobalExclusionTypeHelp" class="inline-help-node">
        <div v-show="localExclusionTypeForQueryParams === 'no_exclusions'">
          {{ translate('SitesManager_ExclusionTypeDescriptionNoExclusions') }}
        </div>
        <div v-show="localExclusionTypeForQueryParams === 'common_pii_exclusions'">
          {{ translate(
            'SitesManager_ExclusionTypeDescriptionCommonPIIExclusions',
            commonSensitiveQueryParams.join(', ')
          ) }}
        </div>
        <div v-show="localExclusionTypeForQueryParams === 'custom_exclusions'">
          {{ translate('SitesManager_ExclusionTypeDescriptionCustomExclusions') }}
        </div>
      </div>

      <div>
        <Field
          uicontrol="radio"
          name="exclusionType"
          :introduction="translate('SitesManager_GlobalListExcludedQueryParameters')"
          :options="exclusionTypeOptions"
          v-model="localExclusionTypeForQueryParams"
          :inline-help="'#excludedQueryParametersGlobalExclusionTypeHelp'"
        />
      </div>

      <div v-show="localExclusionTypeForQueryParams === 'custom_exclusions'">
        <Field
          uicontrol="textarea"
          name="excludedQueryParametersGlobal"
          var-type="array"
          class="limited-height-scrolling-textarea"
          v-model="localExcludedQueryParametersGlobal"
          :model-value="localExcludedQueryParametersGlobal.join('\n')"
          @input="onInputExcludedQueryParametersGlobal($event.target.value)"
          :title="translate('SitesManager_ListOfQueryParametersToBeExcludedOnAllWebsites')"
          :inline-help="'#excludedQueryParametersGlobalHelp'"
        />
        <input
          type="button"
          @click="addCommonPIIQueryParams()"
          class="btn"
          value="Add sensible exclusions to my custom list"
        />
      </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, PropType } from 'vue';
import {
  translate,
} from 'CoreHome';
import { Field } from 'CorePluginsAdmin';

interface ExclusionTypeOption {
  value: string;
  key: 'no_exclusions' | 'common_pii_exclusions' | 'custom_exclusions';
}

interface ExcludeQueryParameterSettingsState {
  localExclusionTypeForQueryParams: string;
  localExcludedQueryParametersGlobal: string[];
  exclusionTypeOptions: ExclusionTypeOption[];
}

export default defineComponent({
  components: {
    Field,
  },
  props: {
    exclusionTypeForQueryParams: {
      type: String,
      default: 'no_exclusions',
    },
    excludedQueryParametersGlobal: {
      type: Array as PropType<string[]>,
      default: () => [],
    },
    commonSensitiveQueryParams: {
      type: Array as PropType<string[]>,
      default: () => [],
    },
  },
  data(): ExcludeQueryParameterSettingsState {
    return {
      localExclusionTypeForQueryParams: this.exclusionTypeForQueryParams,
      localExcludedQueryParametersGlobal: this.excludedQueryParametersGlobal,
      exclusionTypeOptions: [
        {
          value: translate('SitesManager_ExclusionTypeOptionNoExclusions'),
          key: 'no_exclusions',
        },
        {
          value: translate('SitesManager_ExclusionTypeOptionCommonPIIExclusions'),
          key: 'common_pii_exclusions',
        },
        {
          value: translate('SitesManager_ExclusionTypeOptionCustomExclusions'),
          key: 'custom_exclusions',
        },
      ],
    };
  },
  watch: {
    exclusionTypeForQueryParams: {
      handler(newExclusionType: string) {
        this.localExclusionTypeForQueryParams = newExclusionType;
      },
    },
    localExclusionTypeForQueryParams: {
      handler(newExclusionType: string) {
        this.updateExclusionType(newExclusionType);
      },
      immediate: true,
    },
    excludedQueryParametersGlobal: {
      handler(excludedQueryParametersGlobal: string[]) {
        this.localExcludedQueryParametersGlobal = excludedQueryParametersGlobal;
      },
    },
  },
  methods: {
    updateExclusionType(value: string) {
      this.$emit('update:exclusionTypeForQueryParams', value);
    },
    onInputExcludedQueryParametersGlobal(value: string) {
      const valueArray = value.split('\n');
      this.$emit('update:excludedQueryParametersGlobal', valueArray);
    },
    addCommonPIIQueryParams() {
      let updatedParams = this.localExcludedQueryParametersGlobal.filter(
        (param) => !this.commonSensitiveQueryParams.includes(param),
      );
      updatedParams = updatedParams.concat(this.commonSensitiveQueryParams);
      this.localExcludedQueryParametersGlobal = updatedParams;
      this.$emit('update:excludedQueryParametersGlobal', updatedParams);
    },
  },
});
</script>
