// eslint-disable-next-line no-undef
const { _x, _nx, sprintf } = window.wp.i18n

const standartHeaders = {
  'Cache-Control': 'no-cache',
  'X-WP-Nonce': window.Selfchat.nonce,
  Pragma: 'no-cache',
  Expires: '0',
  'Content-Type': 'application/json',
}
async function setSelfchatOption(option, value) {
  const response = await fetch(
    window.Selfchat.restUrl.concat('userSettings/save'),
    {
      method: 'POST',
      headers: standartHeaders,
      body: JSON.stringify({
        option: option,
        value: typeof value == 'boolean' ? (value ? 'true' : 'false') : value,
      }),
    }
  )
  if (!response.ok) {
    const message = sprintf(
      _x(`An error has occured: %s`, 'JS', 'selfchat'),
      response.status
    )
    throw new Error(message)
  }
  const json = await response.json()
  return json
}

async function getSelfchatOptions() {
  const response = await fetch(
    window.Selfchat.restUrl
      .concat('userSettings')
      .concat('?nocache=')
      .concat(new Date().getTime()),
    {
      method: 'GET',
      headers: standartHeaders,
    }
  )
  const json = await response.json()
  return json
}

function promiseState(promise) {
  const pendingState = { status: 'pending' }

  return Promise.race([promise, pendingState]).then(
    (value) =>
      value === pendingState ? value : { status: 'fulfilled', value },
    (reason) => ({ status: 'rejected', reason })
  )
}

const debounce = (context, func, delay) => {
  let timer

  return (...args) => {
    if (timer) {
      clearTimeout(timer)
    }

    timer = setTimeout(() => {
      func.apply(context, args)
    }, delay)
  }
}

window.onload = (e) => {
  let SelfchatLastOptions
  const saveSettingsDebounced = debounce(
    this,
    (id, value, callbackOnSuccess = undefined, callbackOnFail = undefined) => {
      setSelfchatOption(id, value)
        .catch((error) => {
          if (callbackOnFail) {
            callbackOnFail(error)
          } else {
            console.log(error)
          }
          window.BBPMError(error.message)
        })
        .then((json) => {
          if (callbackOnSuccess) {
            callbackOnSuccess(json)
          }
          window.BBPMNotice(json.message)
        })
    },
    800
  )
  const setDarkTheme = () => {
    document.body.classList.remove('bm-messages-light')
    document.body.classList.add('bm-messages-dark')
  }
  const setLightTheme = () => {
    document.body.classList.remove('bm-messages-dark')
    document.body.classList.add('bm-messages-light')
  }
  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      if (!mutation.addedNodes) return
      mutation.addedNodes.forEach((node) => {
        if (node.classList && node.classList.contains('bm-user-settings')) {
          SelfchatLastOptions = getSelfchatOptions()
        } else {
          if (node.classList && node.classList.contains('bpbm-user-options')) {
            if (!SelfchatLastOptions) SelfchatLastOptions = getSelfchatOptions()
            let forcedLoaderElement
            if (promiseState(SelfchatLastOptions).status !== 'fulfilled') {
              node.style.display = 'none'
              forcedLoaderElement = Object.assign(
                document.createElement('div'),
                {
                  className: 'bm-loading',
                }
              )
              forcedLoaderElement.append(
                (() => {
                  let icon = Object.assign(document.createElement('span'), {
                    className: 'bm-loading-icon',
                  })
                  icon
                    .appendChild(
                      Object.assign(document.createElement('div'), {
                        className: 'bm-wait-abit',
                      })
                    )
                    .append(
                      document.createElement('div'),
                      document.createElement('div'),
                      document.createElement('div'),
                      document.createElement('div'),
                      document.createElement('div'),
                      document.createElement('div'),
                      document.createElement('div'),
                      document.createElement('div')
                    )
                  return icon
                })(),
                Object.assign(document.createElement('span'), {
                  className: 'bm-loading-text',
                  innerHTML: _x('Loading', 'JS', 'selfchat'),
                })
              )
              node.parentNode.append(forcedLoaderElement)
            }
            SelfchatLastOptions.then((json) => {
              if (forcedLoaderElement) {
                node.style.removeProperty('display')
                forcedLoaderElement.remove()
              }

              let optionsGroupTitleElement = node.firstChild.firstChild
              if (
                optionsGroupTitleElement.classList &&
                optionsGroupTitleElement.classList.contains(
                  'bpbm-user-option-title'
                ) &&
                optionsGroupTitleElement.innerHTML === json[0].title
              ) {
                json[0].options.forEach((option) => {
                  const optionsGroupOptionElement = Object.assign(
                    document.createElement('div'),
                    {
                      className: 'bpbm-user-option',
                    }
                  )
                  optionsGroupOptionElement.append(
                    Object.assign(document.createElement('input'), {
                      id: option.id,
                      onchange: (e) => {
                        let sendValue = e.target.checked,
                          successCallback
                        if (option.id === 'dark_theme') {
                          switch (e.target.value) {
                            case 'auto':
                              e.target.indeterminate = false
                              e.target.checked = false
                              e.target.value = 'no'
                              break
                            case 'no':
                              e.target.indeterminate = false
                              e.target.checked = true
                              e.target.value = 'yes'
                              break
                            case 'yes':
                              e.target.indeterminate = true
                              e.target.checked = false
                              e.target.value = 'auto'
                              break
                          }
                          sendValue = e.target.value
                          successCallback = (json) => {
                            if (json.options.set_theme === 'dark') {
                              setDarkTheme()
                            } else {
                              setLightTheme()
                            }
                          }
                        }
                        saveSettingsDebounced(
                          option.id,
                          sendValue,
                          successCallback
                        )
                      },
                      className: 'bpbm-checkbox',
                      type: 'checkbox',
                      defaultChecked: option.checked,
                      value: option.value,
                      indeterminate: option.value == 'auto' ? true : false,
                    }),
                    Object.assign(document.createElement('label'), {
                      htmlFor: option.id,
                      textContent: option.label,
                    }),
                    Object.assign(document.createElement('div'), {
                      className: 'bpbm-user-option-description',
                      textContent: option.desc,
                    })
                  )
                  node.firstChild.append(optionsGroupOptionElement)
                })
              }
            }).catch((json) => {
              if (forcedLoaderElement) {
                let loadingIcon =
                  forcedLoaderElement.querySelector('.bm-loading-icon')
                if (loadingIcon) {
                  loadingIcon.innerHTML = '&#x26A0;'
                  loadingIcon.style.fontSize = '64px'
                  loadingIcon.style.marginTop = '20px'
                }
                let loadingText =
                  forcedLoaderElement.querySelector('.bm-loading-text')
                if (loadingText) {
                  loadingText.innerHTML = _x('Error', 'JS', 'selfchat')
                }
              }
            })
          }
        }
      })
    })
  })
  observer.observe(document.body, {
    childList: true,
    subtree: true,
  })
}
