require('dotenv').config()
const gulp = require('gulp'),
  sass = require('gulp-sass'),
  autoprefixer = require('gulp-autoprefixer'),
  axis = require('axis'),
  cssnano = require('gulp-cssnano'),
  concat = require('gulp-concat'),
  eslint = require('gulp-eslint-new'),
  webpack = require('webpack-stream'),
  browserSync = require('browser-sync').create()

gulp.task('browser', function () {
  browserSync.init({
    proxy: process.env.WP_HOST_ADDR, // Use your local address
    host: process.env.WP_HOST_ADDR,
    open: 'external',
    port: 8080,
    notify: false,
  })

  gulp.watch(['./scss/**/*.scss', '!node_modules/**'], gulp.series('sass'))
  gulp
    .watch(['./src/**/*.js', '!node_modules/**'], gulp.series('js'))
    .on('change', browserSync.reload)

  // gulp.watch(['../**/*.php', '!.']).on('change', browserSync.reload)
})

gulp.task('sass', function () {
  return gulp
    .src(['./scss/selfchat.scss', '!node_modules/**'])
    .pipe(
      sass({ use: [axis()], outputStyle: 'compressed' }).on(
        'error',
        sass.logError
      )
    )
    .pipe(cssnano())
    .pipe(
      autoprefixer(['last 2 years', '> 1%', 'not dead'], {
        cascade: true,
        add: true,
      })
    )
    .pipe(concat('selfchat.min.css'))
    .pipe(gulp.dest('./css/'))
    .pipe(browserSync.stream())
})

gulp.task('js', function () {
  return gulp
    .src(['./src/**/*.js', '!node_modules/**'])
    .pipe(eslint())
    .pipe(eslint.format())
    .pipe(eslint.failAfterError())
    .pipe(
      webpack({
        output: {
          filename: 'selfchat.min.js',
        },
        mode: 'development',
      })
    )
    .pipe(gulp.dest('./js/'))
})

gulp.task('build', gulp.series('sass', 'js'))
gulp.task('serve', gulp.series('sass', 'js', 'browser'))
